/**
 * IBG Client Outreach – admin behaviour.
 *
 * Vanilla JS only. Confirmation prompts for destructive links/buttons:
 * add data-ibg-confirm="Message" to any element.
 */
(function () {
  "use strict";

  var settings = window.ibgOutreach || { i18n: { confirm: "Are you sure?" } };

  document.addEventListener(
    "click",
    function (event) {
      var target = event.target.closest("[data-ibg-confirm]");
      if (!target) {
        return;
      }
      var message =
        target.getAttribute("data-ibg-confirm") || settings.i18n.confirm;
      if (!window.confirm(message)) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
    },
    true,
  );

  // Lists: show the criteria panel only for segments.
  var typeField = document.getElementById("ibg-list-type");
  var criteria = document.getElementById("ibg-segment-criteria");
  if (typeField && criteria) {
    typeField.addEventListener("change", function () {
      var checked = typeField.querySelector('input[type="radio"]:checked');
      criteria.hidden = !(checked && "segment" === checked.value);
    });
  }
})();
