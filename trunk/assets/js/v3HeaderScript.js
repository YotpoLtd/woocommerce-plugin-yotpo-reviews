(function e(){
  var e = document.createElement("script");
  // languageCode: the page's language when "Match the site language" is on; without it, the loader
  // uses the language set in Yotpo.
  var language = yotpo_settings.language_code ? "?languageCode=" + encodeURIComponent(yotpo_settings.language_code) : "";
  e.type = "text/javascript", e.async = true, e.src = "https://cdn-widgetsrepository.yotpo.com/v1/loader/" + yotpo_settings.app_key + language;
  var t = document.getElementsByTagName("script")[0];
  t.parentNode.insertBefore(e,t)
})();
