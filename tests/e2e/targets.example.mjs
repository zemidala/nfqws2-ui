// Devices the scenarios run against. Copy this file to targets.local.mjs (it is not in git) and put yours in.
// The name is the Playwright project: pw.py tests/e2e --project=router
//   base — the interface address;
//   ssh  — ssh arguments to the device (by key, no password): the scenarios make a temporary login session there
//          and remove it afterwards; the ones that change the config check it is back byte for byte;
//   www  — where the interface lives on the device;
//   conf — the nfqws2 config.
export const TARGETS = {
  // OpenWrt
  router: { base: 'http://192.168.1.1:90/', ssh: ['root@192.168.1.1'], www: '/www/nfqws-ui', conf: '/etc/nfqws2/nfqws2.conf' },
  // Keenetic (Entware): everything under /opt, ssh of Entware on its own port
  stand: { base: 'http://192.168.2.1:8090/', ssh: ['-p', '222', 'root@192.168.2.1'], www: '/opt/share/www/nfqws-ui', conf: '/opt/etc/nfqws2/nfqws2.conf' },
};
