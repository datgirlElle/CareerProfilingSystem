/*
 * ProfilePath: the SHS strand colors, one list for every page (Student Information, Analytics), so a strand always has
 * the same color. Colors from the SHS office (2026-10-26): STEM red, ABM pink, HUMSS blue, ICT green; shades picked to
 * sit well next to the navy and to stay apart from each other.
 *
 * A strand color is only ever shown as a dot or a chart fill with the strand name next to it (css/components.css
 * .pp-strand), never as the only sign: red and green look alike to some color-blind people, and the status badges
 * already use green, amber and blue for something else.
 * Load after js/ui-state.js (it adds to window.PP).
 */
(function () {
  var PP = window.PP = window.PP || {};
  var COLORS = { STEM: '#DC2626', ABM: '#DB2777', HUMSS: '#2563EB', ICT: '#16A34A' };
  var OTHER = '#64748b'; // a strand not in the list (older data)

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  PP.STRAND_COLORS = COLORS;
  /** The color of a strand (gray for one not in the list). */
  PP.strandColor = function (strand) { return COLORS[strand] || OTHER; };
  /** A colored dot and the strand name, as HTML (the name is escaped). */
  PP.strandLabel = function (strand) {
    return '<span class="pp-strand"><span class="pp-strand-dot" style="background:' + PP.strandColor(strand) + '" aria-hidden="true"></span>' + esc(strand) + '</span>';
  };
})();
