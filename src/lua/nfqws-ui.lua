-- nfqws2-ui: «learn» — an orchestrator like «circular» from zapret-auto.lua that remembers what works.
--
-- circular rotates strategies blindly: after `fails` failures it takes the next one in a circle and forgets everything
-- on restart. learn keeps, per host (or its domain with nld=N), how often each strategy opened the connection and how
-- often it failed, takes the best one, and after `fails` failures moves to the next best — not to the next number.
-- Old results fade: a result `half` seconds old weighs half as much (default 7 days), so a strategy that stopped
-- working is not trusted forever.
--
-- Needs zapret-lib.lua and zapret-auto.lua loaded before it (--lua-init order).
-- Same syntax as circular, so a profile switches by one word:
--   --lua-desync=learn:fails=3:time=60 --lua-desync=fake:...:strategy=1 --lua-desync=multisplit:...:strategy=2
-- args: everything circular takes (fails, time, key, hostkey, nld, reqhost, detectors and their args) plus
--   half=<sec> — half-life of old results, default 604800 (7 days)

LEARN_HALF = 604800
-- success = the server sent this much (relative incoming seq). The router queues only the first ~15 reply packets
-- (connbytes 1:15), and a site behind Cloudflare has sent under 4 KB by then — the standard 4096 is never reached.
-- 2 KB of answer means the greeting got past DPI. An explicit inseq=N in the profile wins.
LEARN_INSEQ = 2048

-- decay a record's counts to `now` (weights halve every `half` seconds)
function learn_decay(rec, now, half)
	if rec.t and now > rec.t then
		local k = 0.5 ^ ((now - rec.t) / half)
		rec.ok = rec.ok * k
		rec.fail = rec.fail * k
	end
	rec.t = now
end

-- expected success of a strategy: (ok + 1) / (ok + fail + 2), so an untried one counts as 0.5
function learn_score(rec)
	if not rec then return 0.5 end
	return (rec.ok + 1) / (rec.ok + rec.fail + 2)
end

-- the best strategy number 1..n, skipping `skip`; ties go to the lower number (the order the profile lists them)
function learn_pick(stats, n, now, half, skip)
	local best, bscore
	for i = 1, n do
		if i ~= skip then
			local rec = stats[i]
			if rec then learn_decay(rec, now, half) end
			local s = learn_score(rec)
			if not best or s > bscore then
				best, bscore = i, s
			end
		end
	end
	return best or 1
end

-- count a result for strategy i
function learn_count(stats, i, ok, now, half)
	local rec = stats[i]
	if not rec then
		rec = { ok = 0, fail = 0, t = now }
		stats[i] = rec
	end
	learn_decay(rec, now, half)
	if ok then rec.ok = rec.ok + 1 else rec.fail = rec.fail + 1 end
end

-- a short hash of a string (djb2, 32 bits) — Lua 5.1 has no bit operators, arithmetic is enough
function learn_hash(s)
	local h = 5381
	for i = 1, #s do h = (h * 33 + s:byte(i)) % 4294967296 end
	return string.format("%08x", h)
end

-- how many strategies the plan has (strategy=1..N without gaps, as circular requires) and their signature: the
-- functions and args of every strategy. A saved record is used only while the signature is the same — after the
-- profile changed, «strategy 2» may be another thing, and its old score must not carry over
local function learn_strategies(desync)
	local uniq, parts, n = {}, {}, 0
	for _, instance in pairs(desync.plan) do
		local s = tonumber(instance.arg.strategy)
		if instance.arg.strategy then
			if not s or s < 1 then
				error("learn: strategy number '" .. tostring(instance.arg.strategy) .. "' is invalid")
			end
			uniq[s] = true
			local args = {}
			for k, v in pairs(instance.arg) do
				if k ~= "strategy" then args[#args + 1] = tostring(k) .. "=" .. tostring(v) end
			end
			table.sort(args)
			parts[#parts + 1] = s .. ":" .. tostring(instance.func) .. ":" .. table.concat(args, ":")
		end
	end
	for _ in pairs(uniq) do n = n + 1 end
	if n ~= #uniq then
		error("learn: strategy numbers must start from 1 and increment. gaps are not allowed.")
	end
	table.sort(parts)
	return n, learn_hash(table.concat(parts, "|"))
end

-- ---------- memory on disk ----------
-- nfqws2 runs as nobody: it writes only to /tmp. The interface (root) copies the file next to nfqws2.conf on every
-- cron pass, so what was learned survives a reboot; at start the fresher /tmp copy wins.
-- A line: key <TAB> host <TAB> signature <TAB> current <TAB> count <TAB> i,ok,fail,time;i,ok,fail,time…
LEARN_FILE = "/tmp/nfqws2-learn.txt"
LEARN_KEEP = { "/tmp/nfqws2-learn.txt", "/etc/nfqws2/learn.txt", "/opt/etc/nfqws2/learn.txt" }
-- the interface asks to forget: a line «key <TAB> host» or «*» for everything (the file is made writable for nobody)
LEARN_FORGET = "/tmp/nfqws2-learn.forget"
LEARN_SAVE_MS = 60000

-- parse one saved line into { key, host, sig, cur, n, stats }
function learn_parse(line)
	local key, host, sig, cur, n, list = line:match("^([^\t]+)\t([^\t]+)\t([^\t]+)\t(%d+)\t(%d+)\t(.*)$")
	if not key then return nil end
	local stats = {}
	for i, ok, fail, t in list:gmatch("(%d+),([%d.]+),([%d.]+),(%d+)") do
		stats[tonumber(i)] = { ok = tonumber(ok), fail = tonumber(fail), t = tonumber(t) }
	end
	return { key = key, host = host, sig = sig, cur = tonumber(cur), n = tonumber(n), stats = stats }
end

-- one record as a line (the interface reads the same format)
function learn_line(key, host, rec)
	local parts = {}
	for i = 1, rec.n do
		local s = rec.stats[i]
		if s then parts[#parts + 1] = string.format("%d,%.3f,%.3f,%d", i, s.ok, s.fail, s.t) end
	end
	return table.concat({ key, host, rec.sig, rec.cur or 1, rec.n, table.concat(parts, ";") }, "\t")
end

-- saved records, by key and host: read once, taken over by a host the first time learn meets it
local function learn_load()
	if LEARN_SAVED then return end
	LEARN_SAVED = {}
	for _, path in ipairs(LEARN_KEEP) do
		local f = io.open(path, "r")
		if f then
			for line in f:lines() do
				local r = learn_parse(line)
				if r then
					LEARN_SAVED[r.key] = LEARN_SAVED[r.key] or {}
					LEARN_SAVED[r.key][r.host] = r
				end
			end
			f:close()
			DLOG("learn: loaded " .. path)
			return
		end
	end
end

-- apply «forget» from the interface, then write everything learned (to a temp file, then rename — never half a file)
function learn_save_timer()
	local f = io.open(LEARN_FORGET, "r")
	if f then
		for line in f:lines() do
			local key, host = line:match("^([^\t]+)\t(.+)$")
			for k, hosts in pairs(autostate or {}) do
				for h, rec in pairs(hosts) do
					if rec.learn and (line == "*" or (k == key and h == host)) then hosts[h] = nil end
				end
			end
			for k, hosts in pairs(LEARN_SAVED or {}) do
				for h in pairs(hosts) do
					if line == "*" or (k == key and h == host) then hosts[h] = nil end
				end
			end
		end
		f:close()
		local w = io.open(LEARN_FORGET, "w")   -- emptied, not removed: /tmp is sticky and the file is root's
		if w then w:close() end
	end
	local lines = {}
	for key, hosts in pairs(autostate or {}) do
		for host, rec in pairs(hosts) do
			if rec.learn then lines[#lines + 1] = learn_line(key, host, rec) end
		end
	end
	-- saved records nobody met since the start are kept as they were
	for key, hosts in pairs(LEARN_SAVED or {}) do
		for host, r in pairs(hosts) do
			if not (autostate and autostate[key] and autostate[key][host] and autostate[key][host].learn) then
				lines[#lines + 1] = learn_line(key, host, r)
			end
		end
	end
	table.sort(lines)
	local tmp = LEARN_FILE .. ".new"
	local w = io.open(tmp, "w")
	if not w then
		DLOG_ERR("learn: cannot write " .. tmp)
		return
	end
	w:write(table.concat(lines, "\n"), #lines > 0 and "\n" or "")
	w:close()
	os.rename(tmp, LEARN_FILE)
end

-- the key and host a record is stored under — the same ones automate_host_record uses
local function learn_keys(desync)
	local key = (desync.arg.key and #desync.arg.key > 0) and desync.arg.key or desync.func_instance
	local hkf = desync.arg.hostkey and _G[desync.arg.hostkey] or standard_hostkey
	return key, hkf(desync)
end

function learn(ctx, desync)
	-- take over execution, as circular does
	orchestrate(ctx, desync)

	if not desync.track then
		DLOG_ERR("learn: conntrack is missing but required")
		return
	end
	local hrec = automate_host_record(desync)
	if not hrec then
		DLOG("learn: passing with no tampering")
		return
	end
	if not LEARN_TIMER then
		-- every minute: apply «forget», write the file (a repeating timer, not oneshot)
		LEARN_TIMER = true
		timer_set("learn_save", "learn_save_timer", LEARN_SAVE_MS, false)
	end
	if not hrec.n then
		hrec.n, hrec.sig = learn_strategies(desync)
		if hrec.n == 0 then
			error("learn: add strategy=N tag argument to each following instance ! N must start from 1 and increment")
		end
		hrec.stats = {}
		hrec.learn = true
		-- what was learned before (another start of nfqws2), if the strategies are still the same
		learn_load()
		local key, host = learn_keys(desync)
		local saved = LEARN_SAVED[key] and LEARN_SAVED[key][host]
		if saved and saved.sig == hrec.sig and saved.n == hrec.n then
			hrec.stats = saved.stats
			hrec.cur = saved.cur
			DLOG("learn: restored " .. key .. " " .. host)
		end
	end
	local half = tonumber(desync.arg.half) or LEARN_HALF
	local now = os.time()
	if not hrec.cur then
		hrec.cur = learn_pick(hrec.stats, hrec.n, now, half)
		DLOG("learn: start with strategy " .. hrec.cur)
	end

	-- a connection keeps the strategy it started with: its success or failure belongs to that one
	local crec = automate_conn_record(desync)
	if not crec.learn_strategy then
		crec.learn_strategy = hrec.cur
	end
	local mine = crec.learn_strategy

	-- detectors read the orchestrator's own args (fails, time, maxseq…): before the instances replace desync.arg
	if not desync.arg.inseq then desync.arg.inseq = LEARN_INSEQ end
	if not crec.nocheck then
		local success_detector = desync.arg.success_detector and _G[desync.arg.success_detector] or standard_success_detector
		local failure_detector = desync.arg.failure_detector and _G[desync.arg.failure_detector] or standard_failure_detector
		if success_detector(desync, crec) then
			crec.nocheck = true
			learn_count(hrec.stats, mine, true, now, half)
			automate_failure_counter_reset(hrec)
			DLOG("learn: success with strategy " .. mine)
		elseif failure_detector(desync, crec) then
			crec.nocheck = true
			learn_count(hrec.stats, mine, false, now, half)
			DLOG("learn: failure with strategy " .. mine)
			local fails = tonumber(desync.arg.fails) or 3
			local maxtime = tonumber(desync.arg.time) or 60
			-- move on only if the failing one is still the host's current choice (another connection may have moved it)
			if automate_failure_counter(hrec, crec, fails, maxtime) and hrec.cur == mine and hrec.n > 1 then
				hrec.cur = learn_pick(hrec.stats, hrec.n, now, half, mine)
				DLOG("learn: switch to strategy " .. hrec.cur)
			end
		end
	end

	local verdict = VERDICT_PASS
	while true do
		local instance = plan_instance_pop(desync)
		if not instance then break end
		if tonumber(instance.arg.strategy) == mine then
			verdict = plan_instance_execute(desync, verdict, instance)
		end
	end
	return verdict
end
