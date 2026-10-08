(function () {
  document.querySelectorAll("[data-embed]").forEach(function (button) {
    button.addEventListener("click", function () {
      var url = button.getAttribute("data-embed") || "";
      var id = "";
      var match = url.match(/spotify\.com\/(?:intl-[a-z]+\/)?(album|track|playlist)\/([A-Za-z0-9]+)/);
      var slot = document.getElementById("player-slot");
      if (!match || !slot) {
        window.open(url, "_blank", "noopener");
        return;
      }
      slot.hidden = false;
      slot.innerHTML = "";
      var frame = document.createElement("iframe");
      frame.title = "Spotify Player";
      frame.allow = "autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture";
      frame.src = "https://open.spotify.com/embed/" + match[1] + "/" + match[2];
      slot.appendChild(frame);
      button.disabled = true;
    });
  });
})();
