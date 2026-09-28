/*
 * Interface d'administration : icônes, menu latéral, menus déroulants,
 * confirmations et graphiques (Chart.js). Aucune dépendance à Turbo ni à Stimulus.
 */
import './styles/admin.css';
import { createIcons, icons } from 'lucide';
import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);
Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
Chart.defaults.color = '#6b7489';

createIcons({ icons, attrs: { 'stroke-width': 1.8 } });

// Menu latéral : réduit (grand écran) ou ouvert par-dessus la page (petit écran).
const CLE_BARRE = 'logetogo-admin-barre-reduite';
try {
  if (localStorage.getItem(CLE_BARRE) === '1') document.body.classList.add('barre-reduite');
} catch { /* stockage indisponible */ }

document.querySelector('[data-basculer-barre]')?.addEventListener('click', () => {
  if (window.matchMedia('(max-width: 1000px)').matches) {
    document.body.classList.toggle('barre-ouverte');
    return;
  }
  const reduite = document.body.classList.toggle('barre-reduite');
  try { localStorage.setItem(CLE_BARRE, reduite ? '1' : '0'); } catch { /* idem */ }
});

// Menus déroulants (cloche, profil) : un seul ouvert à la fois, fermés au clic à côté.
document.addEventListener('click', (evenement) => {
  const declencheur = evenement.target.closest('[data-deroulant]');
  document.querySelectorAll('.deroulant--ouvert').forEach((d) => {
    if (!declencheur || d !== declencheur.closest('.deroulant')) d.classList.remove('deroulant--ouvert');
  });
  if (declencheur) declencheur.closest('.deroulant').classList.toggle('deroulant--ouvert');
});

// Actions sensibles : confirmation avant l'envoi du formulaire.
document.addEventListener('submit', (evenement) => {
  const message = evenement.target.dataset.confirmer;
  if (message && !window.confirm(message)) evenement.preventDefault();
});

// Refus d'une vérification : le motif s'affiche au premier clic.
document.querySelectorAll('[data-afficher]').forEach((bouton) => {
  bouton.addEventListener('click', () => {
    const cible = document.getElementById(bouton.dataset.afficher);
    cible?.classList.remove('cache');
    cible?.querySelector('textarea, input')?.focus();
    bouton.classList.add('cache');
  });
});

// Graphiques : <canvas data-graphique='{"type": "ligne", ...}'>
const COULEURS = { bleu: '#1d6fe8', jaune: '#f5b400', marron: '#8b3a0f', bleuClair: '#3faef0', gris: '#9aa3b5', vert: '#1e9e4a' };

function degrade(contexte, couleur) {
  const d = contexte.createLinearGradient(0, 0, 0, contexte.canvas.clientHeight || 230);
  d.addColorStop(0, `${couleur}40`);
  d.addColorStop(1, `${couleur}00`);
  return d;
}

document.querySelectorAll('canvas[data-graphique]').forEach((canvas) => {
  const config = JSON.parse(canvas.dataset.graphique);
  const contexte = canvas.getContext('2d');

  if (config.type === 'anneau') {
    new Chart(canvas, {
      type: 'doughnut',
      data: { labels: config.libelles, datasets: [{ data: config.valeurs, backgroundColor: config.couleurs.map((c) => COULEURS[c] ?? c), borderWidth: 0 }] },
      options: { cutout: '64%', plugins: { legend: { display: false } }, maintainAspectRatio: false },
    });
    return;
  }

  const ligne = config.type === 'ligne';
  new Chart(canvas, {
    type: ligne ? 'line' : 'bar',
    data: {
      labels: config.libelles,
      datasets: config.series.map((serie) => {
        const couleur = COULEURS[serie.couleur] ?? serie.couleur;
        return {
          label: serie.nom,
          data: serie.valeurs,
          borderColor: couleur,
          backgroundColor: ligne ? degrade(contexte, couleur) : couleur,
          fill: ligne,
          tension: 0.35,
          pointRadius: ligne ? 4 : 0,
          pointBackgroundColor: couleur,
          borderWidth: 2.5,
          borderRadius: ligne ? 0 : 6,
          maxBarThickness: 28,
        };
      }),
    },
    options: {
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { display: !!config.legende, position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
      scales: {
        x: { grid: { display: false }, stacked: !!config.empile },
        y: { beginAtZero: true, stacked: !!config.empile, ticks: { precision: 0 }, grid: { color: '#eef1f6' }, border: { display: false } },
      },
    },
  });
});
