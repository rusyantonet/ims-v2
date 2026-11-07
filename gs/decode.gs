function decodeIndexJs() {
  const html = HtmlService.createHtmlOutputFromFile('Index').getContent();
  const m = html.match(/var P = \[([\s\S]*?)\];/);
  if (!m) throw new Error('Array P tidak ditemukan di Index.html');
  const parts = m[1].match(/'([^']*)'/g).map(s => s.slice(1, -1));
  let b = parts.join('').replace(/-/g, '+').replace(/_/g, '/');
  while (b.length % 4) b += '=';
  const js = Utilities.newBlob(Utilities.base64Decode(b)).getDataAsString('UTF-8');
  const f = DriveApp.createFile('app.js', js, MimeType.PLAIN_TEXT);
  Logger.log('File dibuat: ' + f.getUrl());
}
