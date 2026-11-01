<?php

use Aura\Core\View;

/**
 * Icône SVG inline (jeu Lucide 1.66.0, licence ISC, https://lucide.dev) : un seul jeu pour toute
 * l'application, pas de police d'icônes ni de CDN. Le tracé est copié ici tel quel ; pour ajouter
 * une icône, coller l'intérieur du <svg> de lucide-static/icons/<nom>.svg dans $icons.
 *
 * Usage : View::render('components/icon', ['name' => 'clock'])                 → décorative (aria-hidden)
 *         View::render('components/icon', ['name' => 'clock', 'label' => '…']) → porteuse de sens (role="img")
 *
 * @var string $name nom Lucide (clock, circle-check, triangle-alert…)
 * @var string|null $label texte alternatif ; null ou absent = icône décorative
 * @var string|null $class classes CSS supplémentaires (icon--lg…)
 */
$icons = [
    'ban' => '<circle cx="12" cy="12" r="10"/><path d="M4.929 4.929 19.07 19.071"/>',
    'calendar' => '<path d="M8 2v3"/><path d="M16 2v3"/><rect x="3" y="3" width="18" height="18" rx="2"/>'
        . '<path d="M3 9h18"/>',
    'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
    'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
    'circle-alert' => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/>'
        . '<line x1="12" x2="12.01" y1="16" y2="16"/>',
    'circle-check' => '<circle cx="12" cy="12" r="10"/><path d="m16 9-5.5 5.5L8 12"/>',
    'circle-x' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
    'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    'clock-alert' => '<path d="M12 6v6l4 2"/><path d="M20 12v5"/><path d="M20 21h.01"/>'
        . '<path d="M21.25 8.2A10 10 0 1 0 16 21.16"/>',
    'inbox' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/>'
        . '<path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24'
        . 'a2 2 0 0 0-1.79 1.11z"/>',
    'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
    'menu' => '<path d="M4 5h16"/><path d="M4 12h16"/><path d="M4 19h16"/>',
    'moon' => '<path d="M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.46.402.803a6 6 0 0 0 8.268 8.268'
        . 'c.344-.215.825-.004.803.401"/>',
    'octagon-x' => '<path d="m15 9-6 6"/><path d="M2.586 16.726A2 2 0 0 1 2 15.312V8.688a2 2 0 0 1 .586-1.414'
        . 'l4.688-4.688A2 2 0 0 1 8.688 2h6.624a2 2 0 0 1 1.414.586l4.688 4.688A2 2 0 0 1 22 8.688v6.624'
        . 'a2 2 0 0 1-.586 1.414l-4.688 4.688a2 2 0 0 1-1.414.586H8.688a2 2 0 0 1-1.414-.586z"/>'
        . '<path d="m9 9 6 6"/>',
    'pen-line' => '<path d="M13 21h8"/><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83'
        . 'l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/>',
    'printer' => '<path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>'
        . '<path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"/><rect x="6" y="14" width="12" height="8" rx="1"/>',
    'search' => '<path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/>',
    'shield-check' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1'
        . 'c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>'
        . '<path d="m9 12 2 2 4-4"/>',
    'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/>'
        . '<path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/>'
        . '<path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
    'triangle-alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/>'
        . '<path d="M12 9v4"/><path d="M12 17h.01"/>',
    'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
];

// Nom inconnu : icône « info » plutôt qu'un SVG vide, pour que l'oubli se voie.
$name = isset($icons[$name]) ? $name : 'info';
$label = $label ?? null;
$class = trim('icon icon-' . $name . ' ' . ($class ?? ''));
?>
<svg class="<?= View::e($class) ?>" viewBox="0 0 24 24" width="24" height="24"
     <?= $label === null ? 'aria-hidden="true" focusable="false"' : 'role="img" aria-label="' . View::e($label) . '"' ?>
><?= $icons[$name] ?></svg>
