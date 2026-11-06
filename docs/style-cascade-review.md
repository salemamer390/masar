# Superseded CSS declarations

This review removed 109 older declarations: 30 shared, 12 dark-mode, and 67 dashboard declarations. It does not change the final chosen values or reorder surviving rules.

## Removal rule

For every removed declaration, every selector in its rule has a later declaration of the same property, with the same importance and identical conditional context. Grouped selectors were checked individually. Older values were valid legacy colors, lengths, flex/grid settings, and typography, not vendor-prefix fallback sequences. Stylesheet CSSOM manipulation was not found in application code. No layer/scope rules were moved.

This is deliberately narrower than a full cascade/unused-CSS audit. Repeated selectors that add different properties, different specificity, responsive overrides, and Bootstrap utilities remain. !important was not globally removed. payroll/employee-salaries.css had no same-context/same-priority shadowed declarations of this form.

## Evidence map

Line numbers refer to the pre-consolidation assets in PR31 commit 48350d6ceaef6b22c4c61b78cc75b1e168e50127. The retained declaration may itself be superseded by a later declaration in the same chain. No declaration in that chain becomes active by removing the older entries.

| Asset | Removed line | Selector | Property | Later line(s) |
|---|---:|---|---|---|
| public/app.css | 4896 | `.stat-grid.compact, .payroll-employee-kpi-grid` | `grid-template-columns` | 5243, 5243 |
| public/app.css | 4316 | `.filter-bar` | `box-shadow` | 4983 |
| public/app.css | 4315 | `.filter-bar` | `border-radius` | 4982 |
| public/app.css | 2801 | `.payroll-employee-kpi-grid .stat-icon` | `font-size` | 4918 |
| public/app.css | 2799 | `.payroll-employee-kpi-grid .stat-icon` | `height` | 4915 |
| public/app.css | 2798 | `.payroll-employee-kpi-grid .stat-icon` | `width` | 4914 |
| public/app.css | 2766 | `.payroll-employee-kpi-grid .stat-card` | `min-height` | 4907 |
| public/app.css | 1812 | `.filter-bar` | `align-items` | 4986 |
| public/app.css | 1811 | `.filter-bar` | `gap` | 4985 |
| public/app.css | 1809 | `.filter-bar` | `padding` | 4984 |
| public/app.css | 1808 | `.filter-bar` | `border` | 4981 |
| public/app.css | 1807 | `.filter-bar` | `background` | 4980 |
| public/app.css | 1534 | `.documents-toolbar` | `align-items` | 4986 |
| public/app.css | 1533 | `.documents-toolbar` | `gap` | 4985 |
| public/app.css | 1532 | `.documents-toolbar` | `display` | 4989 |
| public/app.css | 426 | `.employee-kpi small` | `line-height` | 4930 |
| public/app.css | 425 | `.employee-kpi small` | `font-size` | 4930 |
| public/app.css | 421 | `.employee-kpi strong` | `margin` | 4928 |
| public/app.css | 420 | `.employee-kpi strong` | `line-height` | 4928 |
| public/app.css | 418 | `.employee-kpi strong` | `font-size` | 4928 |
| public/app.css | 413 | `.employee-kpi b` | `line-height` | 4926 |
| public/app.css | 411 | `.employee-kpi b` | `font-size` | 4926 |
| public/app.css | 406 | `.employee-kpi-icon` | `font-size` | 4918 |
| public/app.css | 404 | `.employee-kpi-icon` | `flex` | 4917 |
| public/app.css | 403 | `.employee-kpi-icon` | `min-width` | 4916 |
| public/app.css | 402 | `.employee-kpi-icon` | `height` | 4915 |
| public/app.css | 401 | `.employee-kpi-icon` | `width` | 4914 |
| public/app.css | 382 | `.employee-kpi` | `min-height` | 4907 |
| public/app.css | 376 | `.employee-kpi-grid` | `gap` | 4901 |
| public/app.css | 375 | `.employee-kpi-grid` | `grid-template-columns` | 4900 |
| public/dark-mode.css | 813 | `body.preview-dark .attendance-ring:before` | `background` | 893 |
| public/dark-mode.css | 582 | `body.preview-dark .payroll-month-picker .mp-month.active` | `color` | 810 |
| public/dark-mode.css | 581 | `body.preview-dark .payroll-month-picker .mp-month:hover` | `color` | 809 |
| public/dark-mode.css | 580 | `body.preview-dark .payroll-month-picker .mp-month` | `color` | 808 |
| public/dark-mode.css | 577 | `body.preview-dark .payroll-month-picker` | `color` | 807 |
| public/dark-mode.css | 532 | `body.preview-dark .attendance-row-rejected td` | `background` | 732 |
| public/dark-mode.css | 531 | `body.preview-dark .attendance-row-late td` | `background` | 730 |
| public/dark-mode.css | 528 | `body.preview-dark .attendance-detail-item` | `border-color` | 740 |
| public/dark-mode.css | 528 | `body.preview-dark .attendance-detail-item` | `background` | 740 |
| public/dark-mode.css | 465 | `body.preview-dark .attention-card` | `background` | 814 |
| public/dark-mode.css | 454 | `body.preview-dark .attendance-ring small` | `color` | 895 |
| public/dark-mode.css | 453 | `body.preview-dark .attendance-ring span` | `color` | 894 |
| public/dashboard-reference.css | 104 | `.reference-kpi-grid--six .reference-kpi>span:last-child b` | `font-size` | 155 |
| public/dashboard-reference.css | 75 | `.dashboard-command-bar--reference` | `grid-template-columns` | 159 |
| public/dashboard-reference.css | 43 | `.reference-kpi-grid--six .reference-kpi strong` | `font-size` | 137 |
| public/dashboard-reference.css | 30 | `.dashboard-reference-layout .btn-light` | `color` | 40 |
| public/dashboard-reference.css | 30 | `.dashboard-reference-layout .btn-light` | `border-radius` | 40 |
| public/dashboard-reference.css | 30 | `.dashboard-reference-layout .btn-light` | `background` | 40 |
| public/dashboard-reference.css | 30 | `.dashboard-reference-layout .panel-head` | `padding` | 40 |
| public/dashboard-reference.css | 29 | `.reference-kpi-grid--six .reference-kpi.money .reference-kpi-icon` | `background` | 44 |
| public/dashboard-reference.css | 29 | `.reference-kpi-grid--six .reference-kpi.money .reference-kpi-icon` | `color` | 44 |
| public/dashboard-reference.css | 29 | `.reference-kpi-grid--six .reference-kpi.purple .reference-kpi-icon` | `background` | 44 |
| public/dashboard-reference.css | 29 | `.reference-kpi-grid--six .reference-kpi.purple .reference-kpi-icon` | `color` | 44 |
| public/dashboard-reference.css | 29 | `.reference-kpi-grid--six .reference-kpi.orange .reference-kpi-icon` | `background` | 44 |
| public/dashboard-reference.css | 29 | `.reference-kpi-grid--six .reference-kpi.orange .reference-kpi-icon` | `color` | 44 |
| public/dashboard-reference.css | 29 | `.reference-kpi-grid--six .reference-kpi.red .reference-kpi-icon` | `background` | 44 |
| public/dashboard-reference.css | 29 | `.reference-kpi-grid--six .reference-kpi.red .reference-kpi-icon` | `color` | 44 |
| public/dashboard-reference.css | 29 | `.reference-kpi-grid--six .reference-kpi.green .reference-kpi-icon` | `background` | 44 |
| public/dashboard-reference.css | 29 | `.reference-kpi-grid--six .reference-kpi.green .reference-kpi-icon` | `color` | 44 |
| public/dashboard-reference.css | 29 | `.reference-kpi-grid--six .reference-kpi.blue .reference-kpi-icon` | `background` | 44 |
| public/dashboard-reference.css | 29 | `.reference-kpi-grid--six .reference-kpi.blue .reference-kpi-icon` | `color` | 44 |
| public/dashboard-reference.css | 28 | `.reference-kpi-grid--six .reference-kpi small` | `color` | 43 |
| public/dashboard-reference.css | 28 | `.reference-kpi-grid--six .reference-kpi strong` | `margin` | 137 |
| public/dashboard-reference.css | 28 | `.reference-kpi-grid--six .reference-kpi strong` | `font-weight` | 43 |
| public/dashboard-reference.css | 28 | `.reference-kpi-grid--six .reference-kpi strong` | `font-size` | 43 |
| public/dashboard-reference.css | 28 | `.reference-kpi-grid--six .reference-kpi b` | `line-height` | 136 |
| public/dashboard-reference.css | 28 | `.reference-kpi-grid--six .reference-kpi b` | `font-size` | 136 |
| public/dashboard-reference.css | 28 | `.reference-kpi-grid--six .reference-kpi-icon` | `font-size` | 134 |
| public/dashboard-reference.css | 28 | `.reference-kpi-grid--six .reference-kpi-icon` | `min-width` | 132 |
| public/dashboard-reference.css | 28 | `.reference-kpi-grid--six .reference-kpi-icon` | `height` | 131 |
| public/dashboard-reference.css | 28 | `.reference-kpi-grid--six .reference-kpi-icon` | `width` | 130 |
| public/dashboard-reference.css | 26 | `.reference-kpi-grid--six .reference-kpi` | `gap` | 127 |
| public/dashboard-reference.css | 26 | `.reference-kpi-grid--six .reference-kpi` | `padding` | 126 |
| public/dashboard-reference.css | 26 | `.reference-kpi-grid--six .reference-kpi` | `min-height` | 125 |
| public/dashboard-reference.css | 21 | `.reference-kpi-grid--six .reference-kpi-icon` | `height` | 102 |
| public/dashboard-reference.css | 21 | `.reference-kpi-grid--six .reference-kpi-icon` | `width` | 102 |
| public/dashboard-reference.css | 5 | `.dashboard-reference-layout .list-square` | `height` | 18 |
| public/dashboard-reference.css | 5 | `.dashboard-reference-layout .list-square` | `width` | 18 |
| public/dashboard-reference.css | 5 | `.dashboard-reference-layout .reference-list>a` | `padding` | 18 |
| public/dashboard-reference.css | 5 | `.dashboard-reference-layout .btn-light` | `background` | 17 |
| public/dashboard-reference.css | 5 | `.dashboard-reference-layout .btn-light` | `color` | 17 |
| public/dashboard-reference.css | 5 | `.dashboard-reference-layout .panel-head h3` | `color` | 17 |
| public/dashboard-reference.css | 5 | `.dashboard-reference-layout .panel-head h3` | `font-size` | 17 |
| public/dashboard-reference.css | 5 | `.dashboard-reference-layout .panel-head` | `padding` | 17 |
| public/dashboard-reference.css | 5 | `.dashboard-reference-layout .reference-panel` | `box-shadow` | 17 |
| public/dashboard-reference.css | 5 | `.dashboard-reference-layout .reference-panel` | `border-radius` | 17 |
| public/dashboard-reference.css | 5 | `.dashboard-reference-column` | `gap` | 17 |
| public/dashboard-reference.css | 5 | `.dashboard-reference-layout` | `gap` | 17 |
| public/dashboard-reference.css | 4 | `.reference-kpi.money .reference-kpi-icon` | `color` | 16 |
| public/dashboard-reference.css | 4 | `.reference-kpi.money .reference-kpi-icon` | `background` | 16 |
| public/dashboard-reference.css | 4 | `.reference-kpi.red .reference-kpi-icon` | `color` | 16 |
| public/dashboard-reference.css | 4 | `.reference-kpi.red .reference-kpi-icon` | `background` | 16 |
| public/dashboard-reference.css | 4 | `.reference-kpi-grid--six .reference-kpi strong` | `font-size` | 16 |
| public/dashboard-reference.css | 4 | `.reference-kpi-grid--six .reference-kpi-icon` | `font-size` | 16 |
| public/dashboard-reference.css | 4 | `.reference-kpi-grid--six .reference-kpi-icon` | `height` | 16 |
| public/dashboard-reference.css | 4 | `.reference-kpi-grid--six .reference-kpi-icon` | `width` | 16 |
| public/dashboard-reference.css | 4 | `.reference-kpi-grid--six .reference-kpi` | `gap` | 16 |
| public/dashboard-reference.css | 4 | `.reference-kpi-grid--six .reference-kpi` | `border-radius` | 16 |
| public/dashboard-reference.css | 4 | `.reference-kpi-grid--six .reference-kpi` | `padding` | 16 |
| public/dashboard-reference.css | 4 | `.reference-kpi-grid--six .reference-kpi` | `min-height` | 16 |
| public/dashboard-reference.css | 4 | `.reference-kpi-grid--six` | `margin-bottom` | 16 |
| public/dashboard-reference.css | 4 | `.reference-kpi-grid--six` | `gap` | 16 |
| public/dashboard-reference.css | 3 | `.dashboard-command-bar--reference a:first-child:hover` | `background` | 15 |
| public/dashboard-reference.css | 3 | `.dashboard-command-bar--reference a:first-child` | `color` | 15 |
| public/dashboard-reference.css | 3 | `.dashboard-command-bar--reference a:first-child` | `border-color` | 15 |
| public/dashboard-reference.css | 3 | `.dashboard-command-bar--reference a:first-child` | `background` | 15 |
| public/dashboard-reference.css | 3 | `.dashboard-command-bar--reference a` | `box-shadow` | 15 |
| public/dashboard-reference.css | 3 | `.dashboard-command-bar--reference a` | `border-radius` | 15 |
| public/dashboard-reference.css | 3 | `.dashboard-command-bar--reference` | `gap` | 15 |

## Validation and limits

The PHP bundle check and full local suite passed: 365 tests / 1776 assertions, with four existing PHPUnit deprecations. Actual rendered fixtures were compared with pre-consolidation assets in Chromium at mobile and desktop sizes. The comparison read computed properties for all elements plus ::before and ::after, not just selector text. Dashboard, attendance, employee list, employee profile, attendance modal, and dashboard intermediate breakpoints were covered. Test-only motion suppression and local copies of the same public fonts/icons avoid network and transition noise. No animation code was changed.

These are local fixtures, not a claim about production data. Browser support outside the existing application requirements was not expanded. This review does not prove every selector is used or every Bootstrap override can be removed. Different selector specificity, !important transitions, shorthand/longhand interactions, and page-specific additions require separate reasoning and must not be deleted by a broad string deduplicator.
