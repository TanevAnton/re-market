/** Bound Google Apps Script. Paste into Extensions > Apps Script, run setupPriceTracker once. */
const PRICE_REPO = 'https://raw.githubusercontent.com/TanevAnton/re-market/price-data/';

function setupPriceTracker() {
  const spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
  if (!spreadsheet) throw new Error('Open this script from the destination spreadsheet.');
  PropertiesService.getScriptProperties().setProperty('PRICE_SHEET_ID', spreadsheet.getId());
  refreshPriceTracker();
  ScriptApp.getProjectTriggers().filter(t => t.getHandlerFunction() === 'refreshPriceTracker').forEach(t => ScriptApp.deleteTrigger(t));
  ScriptApp.newTrigger('refreshPriceTracker').timeBased().everyDays(1).atHour(10).inTimezone('Europe/Sofia').create();
}

function refreshPriceTracker() {
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(1000)) return;
  try {
    const id = PropertiesService.getScriptProperties().getProperty('PRICE_SHEET_ID');
    if (!id) throw new Error('Run setupPriceTracker first.');
    const ss = SpreadsheetApp.openById(id);
    const runSheet = ss.getSheetByName('Tracker run') || ss.insertSheet('Tracker run');
    try {
      const response = UrlFetchApp.fetch(PRICE_REPO + 'status.json', {muteHttpExceptions: true});
      if (response.getResponseCode() !== 200) throw new Error('GitHub results unavailable: ' + response.getResponseCode());
      const run = JSON.parse(response.getContentText());
      const age = Date.now() - Date.parse(run.finished_at);
      if (run.mode !== 'live' || !Number.isFinite(age) || age > 36*3600000 || age < -300000) throw new Error('Results are stale or not a live run.');
      const files = [['Prices','summary.csv'],['Source status','source_status.csv']];
      const loaded = files.map(([name,path]) => {
        const result = UrlFetchApp.fetch(PRICE_REPO + path, {muteHttpExceptions: true});
        if (result.getResponseCode() !== 200) throw new Error(path + ' is unavailable');
        const rows = Utilities.parseCsv(result.getContentText());
        if (!rows.length || rows[0][0] !== (name === 'Prices' ? 'model_id' : 'source')) throw new Error('Unexpected schema in '+path);
        const width = rows[0].length;
        if (rows.some(r => r.length !== width)) throw new Error('Malformed CSV '+path);
        // Number cells remain numeric; untrusted text cannot become a formula.
        return [name,rows.map((r,i) => r.map(v => i > 0 && /^\d+(\.\d+)?$/.test(v) ? Number(v) : /^[=+@-]/.test(v.trimStart()) ? "'"+v : v))];
      });
      loaded.forEach(([name,rows]) => {
        const sheet = ss.getSheetByName(name) || ss.insertSheet(name);
        if (sheet.getMaxRows() < rows.length) sheet.insertRowsAfter(sheet.getMaxRows(), rows.length-sheet.getMaxRows());
        if (sheet.getMaxColumns() < rows[0].length) sheet.insertColumnsAfter(sheet.getMaxColumns(), rows[0].length-sheet.getMaxColumns());
        const oldLast = sheet.getLastRow();
        sheet.getRange(1,1,rows.length,rows[0].length).setValues(rows);
        if (oldLast > rows.length) sheet.getRange(rows.length+1,1,oldLast-rows.length,rows[0].length).clearContent();
        sheet.setFrozenRows(1);
        sheet.getRange(1,1,1,rows[0].length).setFontWeight('bold').setBackground('#eeeeee').setWrap(true);
        sheet.setRowHeight(1,44);
        sheet.setColumnWidths(1,rows[0].length,140);
        if (name === 'Prices') {
          sheet.setColumnWidth(2,300);
          sheet.getRange(2,7,Math.max(1,rows.length-1),6).setNumberFormat('0.00');
        }
      });
      runSheet.getRange('A1:B6').setValues([
        ['Field','Value'],['Last sheet refresh',new Date().toISOString()],['Source run finished',run.finished_at],
        ['Collection health',run.health],['Failed/skipped jobs',run.failed_jobs],['Meaning','Asking / retail observations; review before pricing']
      ]);
    } catch (error) {
      runSheet.getRange('A1:B3').setValues([['Field','Value'],['Refresh error',String(error.message)],['Attempted at',new Date().toISOString()]]);
      throw error;
    }
  } finally {
    lock.releaseLock();
  }
}
