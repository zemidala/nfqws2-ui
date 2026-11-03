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

-- how many strategies the plan has (strategy=1..N without gaps, as circular requires)
local function learn_strategies(desync)
	local uniq, n = {}, 0
	for _, instance in pairs(desync.plan) do
		local s = tonumber(instance.arg.strategy)
		if instance.arg.strategy then
			if not s or s < 1 then
				error("learn: strategy number '" .. tostring(instance.arg.strategy) .. "' is invalid")
			end
			uniq[s] = true
		end
	end
	for _ in pairs(uniq) do n = n + 1 end
	if n ~= #uniq then
		error("learn: strategy numbers must start from 1 and increment. gaps are not allowed.")
	end
	return n
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
	if not hrec.n then
		hrec.n = learn_strategies(desync)
		if hrec.n == 0 then
			error("learn: add strategy=N tag argument to each following instance ! N must start from 1 and increment")
		end
		hrec.stats = {}
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
