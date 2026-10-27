/**
 * Skafos leads -> Google Sheet. Paste this into the Apps Script editor of the target sheet
 * (Extensions > Apps Script), set SECRET, then Deploy > New deployment > Web app:
 *   Execute as: Me      Who has access: Anyone
 * Copy the /exec URL into api/mail-config.php ('sheets' => 'webhook_url') and use the same SECRET there.
 * After editing this script later, use Deploy > Manage deployments > Edit > New version (the URL stays the same).
 */
var SECRET = 'CHANGE_ME';        // any long random string; must match 'secret' in mail-config.php
var SHEET_NAME = 'Leads';        // tab name; created automatically if missing

var COLUMNS = ['submitted_at', 'id', 'name', 'phone', 'service', 'location', 'visit_date', 'visit_time',
               'lead_source', 'utm_medium', 'utm_campaign', 'page_url', 'landing_page', 'referrer'];

function doPost(e) {
  var lock = LockService.getScriptLock();
  try {
    var data = JSON.parse(e.postData.contents);
    if (data.secret !== SECRET) return reply({ ok: false, error: 'unauthorized' });

    lock.waitLock(10000);
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var sheet = ss.getSheetByName(SHEET_NAME) || ss.insertSheet(SHEET_NAME);
    if (sheet.getLastRow() === 0) {
      sheet.appendRow(COLUMNS);
      sheet.setFrozenRows(1);
    }
    var row = COLUMNS.map(function (c) {
      var v = data[c] == null ? '' : String(data[c]);
      // keep phone numbers as text and block spreadsheet formula injection from form input
      return /^[=+\-@]/.test(v) ? "'" + v : v;
    });
    sheet.appendRow(row);
    return reply({ ok: true });
  } catch (err) {
    return reply({ ok: false, error: String(err) });
  } finally {
    try { lock.releaseLock(); } catch (x) {}
  }
}

function reply(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}
