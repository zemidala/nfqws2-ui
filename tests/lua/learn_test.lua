-- offline checks of the choice logic in nfqws-ui.lua (no nfqws2 needed)
-- run: luajit tests/lua/learn_test.lua src/lua/nfqws-ui.lua
dofile(arg[1])
local function eq(a, b, msg) if a ~= b then error(msg .. ': got ' .. tostring(a) .. ', want ' .. tostring(b)) end end
local H = LEARN_HALF
local t0 = 1000000

-- nothing known: the first strategy
local s = {}
eq(learn_pick(s, 3, t0, H), 1, 'empty → 1')
-- strategy 1 failed three times: an untried one (0.5) beats it
for _ = 1, 3 do learn_count(s, 1, false, t0, H) end
eq(learn_pick(s, 3, t0, H, 1), 2, 'after fails skip 1 → 2')
eq(learn_pick(s, 3, t0, H), 2, 'untried 2 beats failed 1')
-- strategy 3 opened twice: best
learn_count(s, 3, true, t0, H); learn_count(s, 3, true, t0, H)
eq(learn_pick(s, 3, t0, H), 3, 'proven 3 is best')
-- 3 then failed once: still ahead of untried 2 (3/5=0.6 > 0.5)
learn_count(s, 3, false, t0, H)
eq(learn_pick(s, 3, t0, H), 3, '2 ok 1 fail still beats untried')
-- a year later the history weighs almost nothing: every score is close to 0.5 (the last good one still a hair ahead)
learn_pick(s, 3, t0 + 365 * 86400, H)
for i = 1, 3 do
	if s[i] and math.abs(learn_score(s[i]) - 0.5) > 0.01 then error('old history fades: strategy ' .. i .. ' score ' .. learn_score(s[i])) end
end
-- decay halves at half-life
local r = { ok = 4, fail = 2, t = t0 }
learn_decay(r, t0 + H, H)
eq(r.ok, 2, 'ok halves'); eq(r.fail, 1, 'fail halves')
-- single strategy: skip gives back 1
eq(learn_pick({}, 1, t0, H, 1), 1, 'only one')
print('ok: choice logic')

-- a saved line reads back the same
local rec = { sig = learn_hash('1:fake:blob=x|2:multisplit:pos=2'), cur = 2, n = 3, stats = { [1] = { ok = 0, fail = 2.5, t = t0 }, [2] = { ok = 7, fail = 0.25, t = t0 + 5 } } }
local line = learn_line('learn_1_1', 'rutracker.org', rec)
local back = learn_parse(line)
eq(back.key, 'learn_1_1', 'key'); eq(back.host, 'rutracker.org', 'host'); eq(back.sig, rec.sig, 'sig')
eq(back.cur, 2, 'cur'); eq(back.n, 3, 'n'); eq(back.stats[2].ok, 7, 'ok'); eq(back.stats[1].fail, 2.5, 'fail'); eq(back.stats[3], nil, 'untried stays empty')
eq(learn_parse('garbage'), nil, 'a broken line is skipped')
-- the signature changes with any argument
if learn_hash('1:fake:repeats=4') == learn_hash('1:fake:repeats=5') then error('hash does not see args') end
print('ok: memory file format')
