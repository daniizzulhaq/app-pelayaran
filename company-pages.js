'use strict';

const pageEnglish = new Map();
document.querySelectorAll('[data-id]').forEach(element => pageEnglish.set(element, element.textContent));
function documentLanguage(next) {
  const language = next === 'id' ? 'id' : 'en';
  document.documentElement.lang = language;
  document.querySelectorAll('[data-id]').forEach(element => element.textContent = language === 'id' ? element.dataset.id : pageEnglish.get(element));
  document.querySelectorAll('[data-lang]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.lang === language)));
  document.title = (document.body.dataset.page === 'profile' ? (language === 'id' ? 'Profil perusahaan' : 'Company profile') : (language === 'id' ? 'Pemberitahuan privasi' : 'Privacy notice')) + ' | Henota Prime Ocean';
  try { localStorage.setItem('hpo-language', language); } catch {}
}
document.querySelectorAll('[data-lang]').forEach(button => button.addEventListener('click', () => documentLanguage(button.dataset.lang)));
let savedLanguage = 'en';
try { savedLanguage = localStorage.getItem('hpo-language') || 'en'; } catch {}
documentLanguage(savedLanguage);
document.getElementById('print-document')?.addEventListener('click', () => window.print());

function applyDocumentConfiguration(config) {
  document.querySelectorAll('[data-contact]').forEach(element => {
    const key = element.dataset.contact;
    const value = config.contact[key];
    if (!value) return;
    element.textContent = value;
    if (element.tagName === 'A') element.href = key === 'phone' ? `tel:+${value.replace(/\D/g, '')}` : `mailto:${value}`;
  });
  document.querySelectorAll('[data-logo]').forEach(image => image.src = config.media.logo);
}
applyDocumentConfiguration(window.HPO_DEFAULT_CONFIG);
if (/^https?:$/.test(location.protocol)) {
  fetch(new URL('api/index.php?route=site', document.baseURI), {cache:'no-store'})
    .then(response => { if (!response.ok) throw new Error('unavailable'); return response.json(); })
    .then(config => { if (config.contact && config.media) applyDocumentConfiguration(config); })
    .catch(() => {});
}
