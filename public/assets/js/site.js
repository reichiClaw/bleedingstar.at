(function () {
  var root = document.documentElement;
  root.classList.add("js");

  var header = document.querySelector(".site-header");
  var toggle = document.querySelector(".nav-toggle");
  var nav = document.getElementById("site-nav");

  if (header) {
    var onScroll = function () {
      header.classList.toggle("is-scrolled", window.scrollY > 8);
    };
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
  }

  if (toggle && nav) {
    toggle.hidden = false;
    toggle.addEventListener("click", function () {
      var open = nav.classList.toggle("is-open");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    });
    nav.addEventListener("click", function (event) {
      if (event.target.closest("a")) {
        nav.classList.remove("is-open");
        toggle.setAttribute("aria-expanded", "false");
      }
    });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && nav.classList.contains("is-open")) {
        nav.classList.remove("is-open");
        toggle.setAttribute("aria-expanded", "false");
        toggle.focus();
      }
    });
  }

  if ("IntersectionObserver" in window && !window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
    var targets = document.querySelectorAll(".section__head, .grid > .card, .services__item, .projects__item, .roster__list, .hire__inner");
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add("is-visible");
          observer.unobserve(entry.target);
        }
      });
    }, { rootMargin: "0px 0px -8% 0px" });
    targets.forEach(function (el) {
      el.setAttribute("data-reveal", "");
      observer.observe(el);
    });
  }

  /* Filmkorn: der Filmstreifen (grain-frames.png) liegt als Kacheln untereinander
     und wird etwa 12-mal pro Sekunde weitergeschaltet. Ohne JS, bei reduzierter
     Bewegung oder mit der Einstellung „Statisch“ bleibt die Kachel aus dem CSS. */
  var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
  var grainSheet = document.querySelector('link[rel="stylesheet"][href*="css/site.css"]');
  if (grainSheet && root.getAttribute("data-grain") !== "static" && !reduceMotion.matches) {
    var grainVersion = (grainSheet.getAttribute("href").match(/\?v=[^&#]*/) || [""])[0];
    var grainUrl = grainSheet.href.replace(/css\/site\.css.*$/, "img/grain-frames.png") + grainVersion;
    var grainImage = new Image();
    grainImage.onload = function () {
      var tile = grainImage.width;
      var frameCount = Math.round(grainImage.height / tile);
      if (!tile || frameCount < 2) { return; }
      var slices = [];
      for (var g = 0; g < frameCount; g++) {
        var slice = document.createElement("canvas");
        slice.width = tile;
        slice.height = tile;
        slice.getContext("2d").drawImage(grainImage, 0, g * tile, tile, tile, 0, 0, tile, tile);
        slices.push(slice);
      }
      var grain = document.createElement("canvas");
      grain.className = "grain";
      grain.setAttribute("aria-hidden", "true");
      document.body.appendChild(grain);
      var grainCtx = grain.getContext("2d");
      var grainFrame = 0;
      var grainTimer = 0;

      function grainScale() {
        var dpr = window.devicePixelRatio || 1;
        var css = dpr >= 1.5 ? 64 : 128;
        return (css * dpr) / tile;
      }

      function paintGrain() {
        var dpr = window.devicePixelRatio || 1;
        var w = Math.round(window.innerWidth * dpr);
        var h = Math.round(window.innerHeight * dpr);
        if (grain.width !== w || grain.height !== h) {
          grain.width = w;
          grain.height = h;
        }
        var scale = grainScale();
        grainCtx.setTransform(1, 0, 0, 1, 0, 0);
        grainCtx.clearRect(0, 0, w, h);
        grainCtx.setTransform(scale, 0, 0, scale, 0, 0);
        grainCtx.fillStyle = grainCtx.createPattern(slices[grainFrame], "repeat");
        grainCtx.fillRect(0, 0, w / scale, h / scale);
      }

      function stopGrain() {
        window.clearInterval(grainTimer);
        grain.remove();
        root.classList.remove("grain-live");
        window.removeEventListener("resize", paintGrain);
      }

      paintGrain();
      root.classList.add("grain-live");
      grainTimer = window.setInterval(function () {
        if (document.hidden) { return; }
        grainFrame = (grainFrame + 1) % frameCount;
        paintGrain();
      }, 83);
      window.addEventListener("resize", paintGrain);
      if (reduceMotion.addEventListener) {
        reduceMotion.addEventListener("change", function () {
          if (reduceMotion.matches) { stopGrain(); }
        });
      }
    };
    grainImage.src = grainUrl;
  }
})();
