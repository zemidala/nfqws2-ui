// Вход: без сессии — форма входа; неверный пароль — понятная ошибка (одна попытка: после нескольких
// неудач подряд роутер на время блокирует вход с этого адреса). Удачный вход не проверяется: пароль не храним.
import { test as base, expect } from '@playwright/test';
import { ssh } from '../fixtures.mjs';

// Неудачная попытка этого сценария не должна копиться в защите от перебора (5 за 10 минут с адреса):
// убираем последнюю запись только для адреса этого ПК
const forgetFail = (target) => ssh(target, `PATH=/opt/bin:$PATH; ip=\${SSH_CLIENT%% *}; f=/tmp/nfqws-ui-login.json; [ -f $f ] || exit 0; `
  + `printf '<?php $f=json_decode(file_get_contents("%s"),true)?:[]; $ip=getenv("IP"); if(!empty($f[$ip])){array_pop($f[$ip]); if(!$f[$ip]) unset($f[$ip]);} file_put_contents("%s", json_encode($f ?: new stdClass));' $f $f > /tmp/e2e-lf.php; `
  + `IP=$ip php-cgi -q -f /tmp/e2e-lf.php; rm -f /tmp/e2e-lf.php`);

base('без входа — форма входа', async ({ page, baseURL }) => {
  await page.goto(baseURL);
  await expect(page.locator('#login-pass')).toBeVisible();
  await expect(page.locator('#login-user')).toHaveValue('root');
});

base('неверный пароль — ошибка на форме', async ({ page, baseURL }, info) => {
  await page.goto(baseURL);
  await page.fill('#login-pass', 'e2e-wrong-' + Date.now());
  await page.click('button[type=submit]');
  try {
    await expect(page.locator('.login [role=alert]')).not.toBeEmpty();
    await expect(page.locator('#login-pass')).toBeVisible();
  } finally {
    forgetFail(info.project.use.target);
  }
});
