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
