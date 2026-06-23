<?php
require_once __DIR__ . '/../includes/auth.php';

verificarLogin('ADMIN');

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function adminPageStart(string $title): void
{
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo e($title); ?> - Controle Big TI</title>
        <style>
            :root {
                --bg: #05080c;
                --panel: #10151c;
                --line: #242c37;
                --text: #f7f8fb;
                --muted: #a7b0be;
                --red: #e50914;
                --green: #27b84d;
                --radius: 8px;
            }

            * { box-sizing: border-box; }
            body {
                margin: 0;
                min-height: 100vh;
                color: var(--text);
                font-family: "Segoe UI", Arial, sans-serif;
                background: linear-gradient(135deg, #040609 0%, #081018 52%, #05070b 100%);
                animation: pageFadeIn .24s ease both;
                transition: opacity .22s ease, transform .22s ease;
            }
            @media (min-width: 1024px) {
                body { zoom: .82; }
            }
            body.page-leaving {
                opacity: 0;
                transform: translateY(4px);
            }
            @keyframes pageFadeIn {
                from { opacity: 0; transform: translateY(4px); }
                to { opacity: 1; transform: translateY(0); }
            }
            a { color: inherit; text-decoration: none; }
            .page {
                padding: 32px;
                max-width: 1440px;
                margin: 0 auto;
            }
            .top {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 16px;
                margin-bottom: 24px;
            }
            .top h1 {
                margin: 0 0 8px;
                font-size: 30px;
            }
            .top p {
                margin: 0;
                color: var(--muted);
            }
            .btn {
                min-height: 38px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border: 1px solid var(--line);
                border-radius: var(--radius);
                background: transparent;
                color: #fff;
                padding: 0 14px;
                font-weight: 700;
                cursor: pointer;
            }
            .btn.primary {
                background: var(--red);
                border-color: var(--red);
            }
            .panel {
                background: linear-gradient(150deg, rgba(255, 255, 255, .045), transparent 40%), rgba(17, 22, 30, .88);
                border: 1px solid var(--line);
                border-radius: var(--radius);
                overflow: hidden;
                margin-bottom: 18px;
            }
            .filters {
                display: grid;
                grid-template-columns: repeat(6, minmax(140px, 1fr));
                gap: 12px;
                padding: 18px;
            }
            label {
                display: grid;
                gap: 6px;
                color: #f4f6fa;
                font-size: 13px;
                font-weight: 700;
            }
            input, select {
                height: 38px;
                border: 1px solid var(--line);
                border-radius: var(--radius);
                background: #10151c;
                color: #fff;
                padding: 0 10px;
                font: inherit;
            }
            .limit-control {
                display: flex;
                align-items: center;
                gap: 8px;
                align-self: end;
            }
            .limit-control .filter-icon {
                width: 38px;
                height: 38px;
                flex: 0 0 38px;
                display: grid;
                place-items: center;
                border: 1px solid var(--line);
                border-radius: var(--radius);
                background: rgba(255, 255, 255, .035);
                color: var(--muted);
            }
            .limit-control .filter-icon svg { width: 17px; height: 17px; }
            .limit-control select { width: 74px; min-width: 74px; }
            .table-wrap { overflow-x: auto; }
            table {
                width: 100%;
                border-collapse: collapse;
                min-width: 860px;
            }
            th, td {
                text-align: left;
                padding: 14px 18px;
                border-bottom: 1px solid rgba(255, 255, 255, .08);
                white-space: nowrap;
            }
            th {
                font-size: 13px;
                color: #f1f4f8;
            }
            td {
                font-size: 14px;
            }
            .empty {
                padding: 22px;
                color: var(--muted);
            }
            .pagination {
                display: flex;
                gap: 8px;
                padding: 18px;
                justify-content: flex-end;
            }
            .pagination a, .pagination span {
                min-width: 38px;
                height: 38px;
                display: grid;
                place-items: center;
                border: 1px solid var(--line);
                border-radius: var(--radius);
            }
            .pagination .active {
                background: var(--red);
                border-color: var(--red);
                font-weight: 800;
            }
            .badge {
                display: inline-flex;
                min-height: 26px;
                align-items: center;
                padding: 0 10px;
                border-radius: 6px;
                background: rgba(24, 98, 161, .48);
                font-weight: 800;
            }
            .badge.ok { background: rgba(36, 160, 71, .35); }
            .actions-note {
                color: var(--muted);
                padding: 0 18px 18px;
                font-size: 13px;
            }

            @media (max-width: 720px) {
                body { overflow-x: hidden; }
                .page { padding: 16px; }
                .top { gap: 14px; }
                .top h1 { font-size: 24px; line-height: 1.15; }
                .top p { font-size: 13px; }
                .panel { border-radius: 8px; }
                .filters, .form-grid, .detail-grid, .users-toolbar, .stock-form, .stock-search, .maintenance-form, .maintenance-filter, .mov-filters { grid-template-columns: 1fr !important; }
                .filter-actions, .action-row, .stock-actions, .maintenance-actions { width: 100%; justify-content: stretch !important; flex-direction: column; align-items: stretch; }
                .btn, button, .filter-actions .btn, .action-row .btn { min-height: 44px; width: 100%; justify-content: center; }
                input, select, textarea { min-height: 44px; font-size: 16px; }
                .table-wrap { overflow: visible; }
                table { min-width: 0 !important; width: 100%; border-collapse: separate; border-spacing: 0 10px; table-layout: auto !important; }
                thead { display: none; }
                tbody { display: grid; gap: 10px; }
                tr { display: block; border: 1px solid rgba(255,255,255,.08); border-radius: 8px; background: rgba(255,255,255,.035); padding: 10px 12px; }
                td { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border: 0 !important; padding: 9px 0 !important; white-space: normal !important; overflow-wrap: anywhere; text-align: right; }
                td::before { content: attr(data-label); color: var(--muted); font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .45px; text-align: left; flex: 0 0 42%; }
                td[colspan] { display: block; text-align: center; color: var(--muted); }
                td[colspan]::before { content: none; }
                .pagination { justify-content: center; flex-wrap: wrap; }
            }
            @media (max-width: 980px) {
                .filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
                .top { align-items: flex-start; flex-direction: column; }
            }
            @media (max-width: 560px) {
                .page { padding: 20px; }
                .filters { grid-template-columns: 1fr; }
            }
        </style>
    </head>
    <body>
    <main class="page">
    <?php
}

function adminPageEnd(): void
{
    ?>
    </main>
    <script>
        document.querySelectorAll('table').forEach((table) => {
            const headers = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent.trim());
            table.querySelectorAll('tbody tr').forEach((row) => {
                Array.from(row.children).forEach((cell, index) => {
                    if (!cell.hasAttribute('data-label') && headers[index]) cell.setAttribute('data-label', headers[index]);
                });
            });
        });
        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[href]');
            if (!link || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            if (link.target && link.target !== '_self') return;
            const href = link.getAttribute('href');
            if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
            event.preventDefault();
            document.body.classList.add('page-leaving');
            setTimeout(() => { window.location.href = link.href; }, 180);
        });
    </script>
    </body></html>
    <?php
}

function normalizeStatus(?string $status): string
{
    $value = strtoupper(trim((string) $status));

    return match ($value) {
        'CONCLUIDA' => 'Concluída',
        'EM AVALIACAO', 'EM_AVALIACAO' => 'Em avaliação',
        'PENDENTE' => 'Pendente',
        'CANCELADA' => 'Cancelada',
        default => (string) $status,
    };
}

function pageUrl(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    return '?' . http_build_query($params);
}
