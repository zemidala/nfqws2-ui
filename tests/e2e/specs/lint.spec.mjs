// Проверка конфига: маскировки nfqws первого zapret и cond/cond_neg (вопрос Федора, #302). Только lint_raw —
// конфиг не меняется, поэтому годится и для роутера владельца.
import { test, expect, ssh } from '../fixtures.mjs';
import { TARGETS } from '../playwright.config.mjs';

const CUSTOM = `
NFQWS_ARGS_CUSTOM="--filter-tcp=443 --filter-l7=tls --payload=tls_client_hello
--lua-desync=multisplit:pos=1:badsum:badseq:md5sig
--lua-desync=fake:blob=tls_clienthello:tcp_md5:md5sig:tcp_seq=5:badseq
--lua-desync=fake:blob=tls_clienthello:cond=cond_tcp_has_ts
--lua-desync=per_instance_condition
--lua-desync=fake:blob=tls_clienthello:ip_ttl=7:cond=cond_tcp_has_ts:cond_neg"
`;

test('старые названия маскировок — замена по смыслу; cond законен после per_instance_condition', async ({ app, target }) => {
  const conf = ssh(target, `cat ${TARGETS[target].conf}`);
  const r = await app.api('lint_raw', { content: conf.replace(/\nNFQWS_ARGS_CUSTOM="[^"]*"/, '') + CUSTOM });
  const iss = (r.data || r).issues.filter((x) => x.var === 'NFQWS_ARGS_CUSTOM');
  const fix = (tok, key) => iss.find((x) => x.tok === tok && x.msg.includes(`«${key}»`))?.fix?.label;
  expect(fix(3, 'badseq')).toBe('Заменить на tcp_seq=-10000:tcp_ack=-66000');   // не «на badsum» — он в шаге уже есть
  expect(fix(3, 'md5sig')).toBe('Заменить на tcp_md5');
  expect(fix(4, 'md5sig')).toBe('Убрать md5sig');                                 // tcp_md5 уже стоит
  expect(fix(4, 'badseq')).toBe('Заменить на tcp_ack=-66000');                     // tcp_seq уже стоит
  expect(fix(5, 'cond')).toBe('Убрать cond');                                      // до per_instance_condition
  expect(iss.filter((x) => x.tok === 7 && /cond/.test(x.msg))).toEqual([]);         // после — без замечаний
});

// Федор, 14.10: «Применить» → «нельзя использовать символы " ` $ \». Две записи «Своих профилей» с Keenetic:
const lintCustom = async (app, target, custom) => {
  const conf = ssh(target, `cat ${TARGETS[target].conf}`).replace(/\nNFQWS_ARGS_CUSTOM="[^"]*"/g, '');
  const r = await app.api('lint_raw', { content: conf + custom });
  return (r.data || r).issues.filter((x) => x.var === 'NFQWS_ARGS_CUSTOM' && x.level !== 'info');
};

test('«\\» с пробелом в конце строки — перенос, а не параметр', async ({ app, target }) => {
  const iss = await lintCustom(app, target, '\nNFQWS_ARGS_CUSTOM="--filter-tcp=443 \\ \n--lua-desync=fake:blob=tls_clienthello \\\t\n--new\n--filter-udp=443 --lua-desync=fake:blob=quic_initial"\n');
  expect(iss.map((x) => x.msg)).toEqual([]);
});

test('NFQWS_ARGS_CUSTOM="$NFQWS_ARGS_CUSTOM …" — одно понятное замечание вместо «ожидается параметр»', async ({ app, target }) => {
  const iss = await lintCustom(app, target, '\nNFQWS_ARGS_CUSTOM="--filter-tcp=443 --lua-desync=fake:blob=tls_clienthello"\nNFQWS_ARGS_CUSTOM="$NFQWS_ARGS_CUSTOM --new --filter-udp=443 --lua-desync=fake:blob=quic_initial"\n');
  expect(iss.length, iss.map((x) => x.msg).join('\n')).toBe(1);
  expect(iss[0].msg).toMatch(/^NFQWS_ARGS_CUSTOM присвоена в конфиге несколько раз/);
});
