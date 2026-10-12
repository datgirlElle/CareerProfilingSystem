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

    // Earliest time that may be picked ("HH:MM", or null for no limit). Choices that would give an earlier time are
    // greyed out (disabled), and a time already chosen that falls before it is cleared. See setMin() below.
    var minTime = null;
    function toHHMM(h, m, p) {
      if (p === 'NN') return (h === '12' && m === '00') ? '12:00' : null;
      var hh = Number(h) % 12;
      if (p === 'PM') hh += 12;
      return String(hh).padStart(2, '0') + ':' + m;
    }
    function allValues(select) {
      return Array.prototype.map.call(select.options, function (o) { return o.value; }).filter(function (v) { return v !== ''; });
    }
    // True when some time that agrees with the given choices is at or after the limit.
    function anyAllowed(hours, minutes, periods) {
      for (var i = 0; i < hours.length; i++) {
        for (var j = 0; j < minutes.length; j++) {
          for (var k = 0; k < periods.length; k++) {
            var t = toHHMM(hours[i], minutes[j], periods[k]);
            if (t !== null && t >= minTime) return true;
          }
        }
      }
      return false;
    }
    function applyMin() {
      [hour, minute, period].forEach(function (s) { Array.prototype.forEach.call(s.options, function (o) { o.disabled = false; }); });
      if (!minTime) return;
      var chosen = {
        h: hour.value ? [hour.value] : allValues(hour),
        m: minute.value ? [minute.value] : allValues(minute),
        p: period.value ? [period.value] : allValues(period)
      };
      Array.prototype.forEach.call(hour.options, function (o) { if (o.value) o.disabled = !anyAllowed([o.value], chosen.m, chosen.p); });
      Array.prototype.forEach.call(minute.options, function (o) { if (o.value) o.disabled = !anyAllowed(chosen.h, [o.value], chosen.p); });
      Array.prototype.forEach.call(period.options, function (o) { if (o.value) o.disabled = !anyAllowed(chosen.h, chosen.m, [o.value]); });
    }
    function setMin(limit) {
      minTime = limit || null;
      if (minTime && hidden.value && hidden.value < minTime) {
        hour.value = ''; minute.value = ''; period.value = '';
        wasNoon = false;
        sync();
      }
      applyMin();
    }

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
      applyMin();
      if (hidden.value !== value) {
        hidden.value = value;
        hidden.dispatchEvent(new Event('input', { bubbles: true }));
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
    [hour, minute, period].forEach(function (s) { s.addEventListener('change', sync); });

    // The form's reset button puts the drop-downs back to their first option; follow it.
    if (hidden.form) hidden.form.addEventListener('reset', function () { setTimeout(sync, 0); });

    return { sync: sync, setMin: setMin };
  }

  window.TimeFmt = { format: format, picker: picker };
})();
