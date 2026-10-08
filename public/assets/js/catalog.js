(function () {
  var form = document.getElementById("release-filter");
  var slot = document.getElementById("release-results");
  if (!form || !slot) return;

  function queryFrom(formEl) {
    var data = new FormData(formEl);
    var params = new URLSearchParams();
    data.forEach(function (value, key) {
      if (String(value).trim() !== "") params.set(key, value);
    });
    return params;
  }

  function load(url, push) {
    var frag = new URL(url, window.location.origin);
    frag.searchParams.set("fragment", "1");
    fetch(frag.toString(), { headers: { Accept: "text/html" } })
      .then(function (res) { return res.text(); })
      .then(function (html) {
        slot.innerHTML = html;
        var clean = new URL(url, window.location.origin);
        clean.searchParams.delete("fragment");
        if (push) history.pushState(null, "", clean.pathname + clean.search);
      })
      .catch(function () { window.location.href = url; });
  }

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    var params = queryFrom(form);
    load("/releases" + (params.toString() ? "?" + params.toString() : ""), true);
  });

  slot.addEventListener("click", function (event) {
    var link = event.target.closest("a");
    if (!link || !link.href || link.origin !== window.location.origin) return;
    if (!link.pathname.endsWith("/releases") && link.pathname !== "/releases") return;
    event.preventDefault();
    load(link.href, true);
  });

  window.addEventListener("popstate", function () {
    load(window.location.href, false);
  });
})();
