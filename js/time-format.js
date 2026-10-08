/*
 * ProfilePath: 12-hour times written as h:mm AM / PM / NN, never 24-hour ("military") time.
 *   TimeFmt.format('13:30')  -> '1:30 PM'
 *   TimeFmt.format('12:00')  -> '12:00 NN'   (noon)
 *   TimeFmt.format('00:15')  -> '12:15 AM'
 * TimeFmt.picker() turns an empty container into hour / minute / AM-PM-NN drop-downs that keep a hidden
 * <input> in the 24-hour "HH:MM" form the server stores (api/exam-schedules.php).
 * The same rule is in PHP as ExamSchedule::formatTime().
 */
(function () {
  'use strict';

  function format(hhmm) {
    var m = /^(\d{1,2}):(\d{2})/.exec(String(hhmm || ''));
    if (!m) return '';
    var h = Number(m[1]), min = Number(m[2]);
    if (h === 12 && min === 0) return '12:00 NN';
    return (h % 12 === 0 ? 12 : h % 12) + ':' + m[2] + ' ' + (h >= 12 ? 'PM' : 'AM');
  }

  var SELECT_CLASS = 'border border-slate-300 rounded-md px-2 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#ed1c24] focus:border-transparent';

  function option(value, text) {
    var o = document.createElement('option');
    o.value = value; o.textContent = text;
    return o;
  }

  /**
   * @param {HTMLElement} box     empty element the three drop-downs are put in
   * @param {HTMLInputElement} hidden  gets "HH:MM" (or '' while the time is incomplete) and an 'input' event on every change
   * @param {string} label        used in the drop-downs' accessible names, e.g. "Start"
   */
  function picker(box, hidden, label) {
    box.style.display = 'flex';
    box.style.alignItems = 'center';
    box.style.gap = '6px';

    var hour = document.createElement('select');
    var minute = document.createElement('select');
    var period = document.createElement('select');
    [hour, minute, period].forEach(function (s) { s.className = SELECT_CLASS; s.style.minWidth = '0'; });
    hour.setAttribute('aria-label', label + ' hour');
    minute.setAttribute('aria-label', label + ' minutes');
    period.setAttribute('aria-label', label + ' AM, PM or noon');

    hour.appendChild(option('', 'Hr'));
    for (var h = 1; h <= 12; h++) hour.appendChild(option(String(h), String(h)));
    minute.appendChild(option('', 'Min'));
    for (var m = 0; m < 60; m += 5) minute.appendChild(option(String(m).padStart(2, '0'), String(m).padStart(2, '0')));
    period.appendChild(option('', 'AM/PM'));
    ['AM', 'PM', 'NN'].forEach(function (p) { period.appendChild(option(p, p)); });

    var colon = document.createElement('span');
    colon.textContent = ':';
    colon.setAttribute('aria-hidden', 'true');
    box.appendChild(hour); box.appendChild(colon); box.appendChild(minute); box.appendChild(period);

    var wasNoon = false;
    function sync() {
      // NN is noon: it is always 12:00, so the hour and minutes are filled in and locked.
      var noon = period.value === 'NN';
      if (noon) { hour.value = '12'; minute.value = '00'; }
      else if (wasNoon) { hour.value = ''; minute.value = ''; } // leaving NN: ask for the time again rather than keep 12:00
      wasNoon = noon;
      hour.disabled = minute.disabled = noon;

      var value = '';
      if (hour.value && minute.value && period.value) {
        var hh = Number(hour.value) % 12;               // 12 -> 0, then add 12 for noon / PM
        if (period.value === 'PM' || noon) hh += 12;
        value = String(hh).padStart(2, '0') + ':' + minute.value;
      }
      if (hidden.value !== value) {
        hidden.value = value;
        hidden.dispatchEvent(new Event('input', { bubbles: true }));
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
    [hour, minute, period].forEach(function (s) { s.addEventListener('change', sync); });

    // The form's reset button puts the drop-downs back to their first option; follow it.
    if (hidden.form) hidden.form.addEventListener('reset', function () { setTimeout(sync, 0); });

    return { sync: sync };
  }

  window.TimeFmt = { format: format, picker: picker };
})();
