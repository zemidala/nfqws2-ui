// Lists are read as nfqws2 reads them (zapret2 nfq2/hostlist.c, ipset.c): «#», «;», «/» first is a comment, only the
// first word of a line counts, «^» and «!» lead a site. «Исправить» turns what nfqws2 skips (not an address, a range,
// a broken name) into a comment instead of leaving it: before, such errors had no fix and the button did nothing.
// Creates two lists on the stand and deletes them at the end — only on the stand.
import { test, expect } from '../fixtures.mjs';

const CASES = [
  ['ipset_zzfix.list',
    '10.0.0.0/8\n1.2.3.4 # office\n; comment\n// comment\n1.2.3.0-1.2.3.255\nexample.com\n300.1.1.1\n  ::1\n10.0.0.0/8\n',
    '10.0.0.0/8\n1.2.3.4 # office\n; comment\n// comment\n# 1.2.3.0-1.2.3.255\n# example.com\n# 300.1.1.1\n  ::1', 4],
  ['zzfix.list',
    'rutracker.org\n!ads.example.com\n^strict.example.com\nbad_host!\nsite.org # note\n',
    'rutracker.org\n!ads.example.com\n^strict.example.com\n# bad_host!\nsite.org # note', 1],
];

test('«Исправить» в списке: что nfqws2 пропускает — в комментарий, свои комментарии и знаки не трогаются', async ({ app, target }) => {
  test.skip(target !== 'stand', 'создаёт списки — только на стенде');
  for (const [name, before, after, fixed] of CASES) {
    await app.api('list_delete', { name });   // left by a run that broke off
    const c = await app.api('list_create', { name });
    expect((c.data ?? c).name, JSON.stringify(c)).toBe(name);
    try {
      const s = await app.api('list_save', { name, content: before });
      expect((s.data ?? s).ok, JSON.stringify(s)).toBe(true);
      const r = await app.api('list_fix', { name });
      expect(r.data?.fixed ?? r.fixed, name).toBe(fixed);
      const g = await app.api('list_get', { name });
      expect((g.data?.content ?? g.content).replace(/\n$/, ''), name).toBe(after);
      // nothing left to fix: the second press changes nothing
      const r2 = await app.api('list_fix', { name });
      expect(r2.data?.fixed ?? r2.fixed, name + ' второй раз').toBe(0);
    } finally {
      await app.api('list_delete', { name });
    }
  }
});
