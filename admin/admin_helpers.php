<?php
require_once __DIR__ . '/../includes/auth.php';

verificarLogin('ADMIN');

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function adminIsFragmentRequest(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        && strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function adminNavItem(string $href, string $label, string $icon, string $current): string
{
    $active = basename($href) === $current ? ' active' : ' muted';
    return '<a class="nav-item' . $active . '" href="' . e($href) . '" title="' . e($label) . '">' . $icon . '<span>' . e($label) . '</span></a>';
}

function adminSidebar(): void
{
    $current = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $nomeUsuario = $_SESSION['nome_usuario'] ?? $_SESSION['usuario_nome'] ?? $_SESSION['usuario'] ?? 'Administrador';
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>',
        'stock' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>',
        'maintenance' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18v3h3l6.3-6.3a4 4 0 0 0 5.4-5.4"/><path d="m15 5 4 4"/></svg>',
        'reports' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M7 14h3v4H7z"/><path d="M12 9h3v9h-3z"/><path d="M17 6h3v12h-3z"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>',
    ];
    ?>
    <aside class="sidebar">
        <button class="sidebar-toggle" type="button" aria-label="Recolher menu" title="Recolher menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <div class="brand" aria-label="Bigmais"><span>Big</span><span>mais</span><span class="plus">+</span></div>
        <nav class="nav" aria-label="Navegação principal">
            <div class="nav-group">
                <?php echo adminNavItem('dashboard.php', 'Painel de Controle', $icons['dashboard'], $current); ?>
            </div>
            <div class="nav-group">
                <div class="nav-label">Gestão</div>
                <?php echo adminNavItem('usuarios.php', 'Usuários', $icons['users'], $current); ?>
                <?php echo adminNavItem('estoque.php', 'Controle de Estoque', $icons['stock'], $current); ?>
                <?php echo adminNavItem('manutencao.php', 'Manutenção', $icons['maintenance'], $current); ?>
                <?php echo adminNavItem('relatorio_setores.php', 'Relatórios', $icons['reports'], $current); ?>
            </div>
        </nav>
        <div class="sidebar-footer">
            <div class="profile"><div class="avatar"><?php echo $icons['users']; ?></div><div><strong><?php echo e($nomeUsuario); ?></strong><span><i class="online-dot"></i>Online</span></div></div>
            <a class="logout" href="../logout.php" title="Sair"><?php echo $icons['logout']; ?><span>Sair</span></a>
        </div>
    </aside>
    <?php
}

function adminPageStart(string $title): void
{
    if (adminIsFragmentRequest()) {
        echo '<main class="page admin-fragment" data-page-title="' . e($title) . '">';
        return;
    }
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo e($title); ?> - Controle Big TI</title>
        <style>
            :root { --bg:#05080c; --panel:#10151c; --line:#242c37; --text:#f7f8fb; --muted:#a7b0be; --red:#e50914; --green:#27b84d; --radius:18px; --sidebar-width:280px; }
            * { box-sizing: border-box; }
            body { margin:0; min-height:100vh; color:var(--text); font-family:"Segoe UI", Arial, sans-serif; background:radial-gradient(circle at 70% 0%, rgba(40,48,62,.24), transparent 35%), linear-gradient(135deg,#040609 0%,#081018 52%,#05070b 100%); animation:pageFadeIn .26s ease both; transition:opacity .24s ease, transform .24s ease; }
            @media (min-width:1024px){ body{ zoom:.82; min-height:122vh; } .app{ min-height:122vh; } .sidebar{ height:122vh; } }
            body.page-leaving{ opacity:0; transform:translateY(4px); }
            @keyframes pageFadeIn{ from{opacity:0; transform:translateY(4px);} to{opacity:1; transform:translateY(0);} }
            a{ color:inherit; text-decoration:none; }
            .app{ display:grid; grid-template-columns:var(--sidebar-width) minmax(0,1fr); min-height:100vh; transition:grid-template-columns .22s ease; }
            .sidebar{ position:sticky; top:0; height:100vh; border-right:1px solid var(--line); background:rgba(4,8,13,.94); display:flex; flex-direction:column; padding:22px; transition:padding .22s ease; }
            .sidebar-toggle{ width:34px; height:34px; border:1px solid rgba(255,255,255,.10); border-radius:10px; background:rgba(255,255,255,.035); color:#fff; display:grid; place-items:center; align-self:flex-end; cursor:pointer; margin-bottom:18px; transition:background .2s ease,border-color .2s ease,transform .2s ease; }
            .sidebar-toggle:hover{ background:rgba(255,255,255,.065); border-color:rgba(255,255,255,.14); transform:translateY(-1px); }
            .sidebar-toggle svg{ width:15px; height:15px; transition:transform .22s ease; }
            .brand{ display:flex; align-items:flex-start; height:70px; font-size:42px; font-weight:800; letter-spacing:0; line-height:1; }
            .brand span:first-child{ color:#fff; } .brand span:nth-child(2){ color:var(--red); } .brand .plus{ color:#fff; background:var(--red); width:18px; height:18px; border-radius:50%; font-size:15px; line-height:17px; display:inline-flex; align-items:center; justify-content:center; margin-left:4px; margin-top:1px; }
            .nav{ display:grid; gap:14px; margin-top:12px; } .nav-group{ display:grid; gap:10px; } .nav-label{ color:var(--muted); font-size:13px; font-weight:700; text-transform:uppercase; margin:18px 14px 4px; }
            .nav-item{ min-height:50px; border-radius:var(--radius); color:#fff; display:flex; align-items:center; gap:12px; padding:0 14px; font-weight:700; border:1px solid transparent; transition:background .2s ease,border-color .2s ease,transform .2s ease,box-shadow .2s ease; }
            .nav-item svg,.logout svg{ width:21px; height:21px; flex:0 0 auto; } .nav-item:hover{ background:rgba(255,255,255,.045); border-color:rgba(255,255,255,.075); transform:translateX(2px); } .nav-item.active{ background:linear-gradient(135deg,var(--red),#f01520); box-shadow:0 10px 24px rgba(229,9,20,.22); }
            .sidebar-footer{ margin-top:auto; border-top:1px solid var(--line); padding-top:18px; display:grid; gap:14px; } .profile{ display:flex; align-items:center; gap:9px; } .avatar{ width:32px; height:32px; border:1px solid rgba(255,255,255,.86); border-radius:50%; display:grid; place-items:center; } .avatar svg{ width:18px; height:18px; } .profile strong{ display:block; font-size:12px; } .profile span{ color:#dce1ea; display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; } .online-dot{ width:7px; height:7px; border-radius:50%; background:var(--green); box-shadow:0 0 0 3px rgba(39,184,77,.12); }
            .logout{ display:inline-flex; align-items:center; justify-content:flex-start; gap:10px; color:#fff; font-weight:700; min-height:46px; width:100%; padding:0 12px; border:1px solid transparent; border-radius:var(--radius); transition:background .2s ease,border-color .2s ease,transform .2s ease; } .logout:hover{ background:rgba(255,255,255,.045); border-color:rgba(255,255,255,.075); transform:translateY(-1px); }
            body.sidebar-collapsed{ --sidebar-width:92px; } body.sidebar-collapsed .sidebar{ align-items:center; padding-left:18px; padding-right:18px; } body.sidebar-collapsed .brand{ width:48px; height:48px; overflow:hidden; justify-content:center; font-size:0; } body.sidebar-collapsed .brand span:first-child{ font-size:28px; } body.sidebar-collapsed .brand span:nth-child(2),body.sidebar-collapsed .brand .plus,body.sidebar-collapsed .nav-label,body.sidebar-collapsed .nav-item span,body.sidebar-collapsed .profile>div,body.sidebar-collapsed .logout span{ display:none; } body.sidebar-collapsed .nav,body.sidebar-collapsed .nav-group,body.sidebar-collapsed .sidebar-footer{ width:100%; } body.sidebar-collapsed .nav-item,body.sidebar-collapsed .logout{ justify-content:center; padding:0; } body.sidebar-collapsed .profile{ justify-content:center; } body.sidebar-collapsed .sidebar-toggle{ align-self:center; } body.sidebar-collapsed .sidebar-toggle svg{ transform:rotate(180deg); }
            .main{ min-width:0; } .topbar{ height:84px; border-bottom:1px solid var(--line); display:flex; justify-content:flex-end; align-items:center; padding:0 32px; background:rgba(5,8,12,.48); } .page{ padding:32px; max-width:1440px; margin:0 auto; width:100%; opacity:1; transform:translateY(0); transition:opacity .18s ease, transform .18s ease; } .page.is-loading{ opacity:.35; transform:translateY(4px); }
            .top{ display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:24px; } .top h1{ margin:0 0 8px; font-size:30px; } .top p{ margin:0; color:var(--muted); }
            .btn{ min-height:38px; display:inline-flex; align-items:center; justify-content:center; border:1px solid var(--line); border-radius:var(--radius); background:transparent; color:#fff; padding:0 14px; font-weight:700; cursor:pointer; transition:background .18s ease,border-color .18s ease,transform .18s ease,box-shadow .18s ease; } .btn:hover{ background:rgba(255,255,255,.055); border-color:rgba(255,255,255,.14); transform:translateY(-1px); } .btn.primary{ background:var(--red); border-color:var(--red); box-shadow:0 10px 24px rgba(229,9,20,.18); }
            .panel{ background:linear-gradient(150deg,rgba(255,255,255,.045),transparent 40%),rgba(17,22,30,.88); border:1px solid var(--line); border-radius:var(--radius); overflow:hidden; margin-bottom:20px; box-shadow:0 18px 44px rgba(0,0,0,.18); transition:border-color .2s ease, transform .2s ease, box-shadow .2s ease; } .panel:hover{ border-color:rgba(255,255,255,.11); box-shadow:0 22px 48px rgba(0,0,0,.24); }
            .filters{ display:grid; grid-template-columns:repeat(6,minmax(140px,1fr)); gap:12px; padding:20px; } label{ display:grid; gap:6px; color:#f4f6fa; font-size:13px; font-weight:700; } input,select,textarea{ border:1px solid var(--line); border-radius:var(--radius); background:#10151c; color:#fff; padding:0 10px; font:inherit; } input,select{ height:38px; }
            .limit-control{ display:flex; align-items:center; gap:8px; align-self:end; } .limit-control .filter-icon{ width:38px; height:38px; flex:0 0 38px; display:grid; place-items:center; border:1px solid var(--line); border-radius:var(--radius); background:rgba(255,255,255,.035); color:var(--muted); } .limit-control .filter-icon svg{ width:17px; height:17px; max-width:17px; max-height:17px; display:block; } .limit-control select{ width:74px; min-width:74px; }
            .table-wrap{ overflow-x:auto; } table{ width:100%; border-collapse:collapse; min-width:860px; } th,td{ text-align:left; padding:14px 18px; border-bottom:1px solid rgba(255,255,255,.08); white-space:nowrap; } th{ font-size:13px; color:#f1f4f8; } td{ font-size:14px; } tr{ transition:background .18s ease; } tr:hover{ background:rgba(255,255,255,.025); } .empty{ padding:22px; color:var(--muted); } .pagination{ display:flex; gap:8px; padding:18px; justify-content:flex-end; } .pagination a,.pagination span{ min-width:38px; height:38px; display:grid; place-items:center; border:1px solid var(--line); border-radius:var(--radius); } .pagination .active{ background:var(--red); border-color:var(--red); font-weight:800; } .badge{ display:inline-flex; min-height:26px; align-items:center; padding:0 10px; border-radius:8px; background:rgba(24,98,161,.48); font-weight:800; } .badge.ok{ background:rgba(36,160,71,.35); } .actions-note{ color:var(--muted); padding:0 18px 18px; font-size:13px; }
            @media (max-width:980px){ .app{ grid-template-columns:1fr; } .sidebar{ position:relative; height:auto; } .filters{ grid-template-columns:repeat(2,minmax(0,1fr)); } .top{ align-items:flex-start; flex-direction:column; } }
            @media (max-width:720px){ body{ overflow-x:hidden; } .page{ padding:16px; } .top h1{ font-size:24px; line-height:1.15; } .top p{ font-size:13px; } .filters,.form-grid,.detail-grid,.users-toolbar,.stock-form,.stock-search,.maintenance-form,.maintenance-filter,.mov-filters{ grid-template-columns:1fr!important; } .filter-actions,.action-row,.stock-actions,.maintenance-actions{ width:100%; justify-content:stretch!important; flex-direction:column; align-items:stretch; } .btn,button,.filter-actions .btn,.action-row .btn{ min-height:44px; width:100%; justify-content:center; } input,select,textarea{ min-height:44px; font-size:16px; } .table-wrap{ overflow:visible; } table{ min-width:0!important; width:100%; border-collapse:separate; border-spacing:0 10px; table-layout:auto!important; } thead{ display:none; } tbody{ display:grid; gap:10px; } tr{ display:grid; border:1px solid rgba(255,255,255,.08); border-radius:12px; background:rgba(255,255,255,.035); padding:10px 12px; box-shadow:0 10px 24px rgba(0,0,0,.12); } td{ display:grid; grid-template-columns:96px minmax(0,1fr); align-items:center; gap:12px; border:0!important; padding:8px 0!important; white-space:normal!important; overflow:visible; overflow-wrap:anywhere; text-align:left; } td::before{ content:attr(data-label); color:var(--muted); font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.45px; } td>*{ min-width:0; } td[colspan]{ display:block; text-align:center; color:var(--muted); } td[colspan]::before{ content:none; } .pagination{ justify-content:center; flex-wrap:wrap; } }
        </style>
    </head>
    <body>
    <div class="app">
        <?php adminSidebar(); ?>
        <main class="main">
            <header class="topbar"></header>
            <main class="page" data-page-title="<?php echo e($title); ?>">
    <?php
}

function adminPageEnd(): void
{
    if (adminIsFragmentRequest()) {
        echo '</main>';
        return;
    }
    ?>
            </main>
        </main>
    </div>
    <script>
        function prepareResponsiveTables(root = document) {
            root.querySelectorAll('table').forEach((table) => {
                const headers = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent.trim());
                table.querySelectorAll('tbody tr').forEach((row) => {
                    Array.from(row.children).forEach((cell, index) => {
                        if (!cell.hasAttribute('data-label') && headers[index]) cell.setAttribute('data-label', headers[index]);
                    });
                });
            });
        }

        function setupSidebarCollapse() {
            const toggle = document.querySelector('.sidebar-toggle');
            const apply = (collapsed) => {
                document.body.classList.toggle('sidebar-collapsed', collapsed);
                toggle?.setAttribute('aria-label', collapsed ? 'Expandir menu' : 'Recolher menu');
                toggle?.setAttribute('title', collapsed ? 'Expandir menu' : 'Recolher menu');
            };
            apply(localStorage.getItem('controleSidebarCollapsed') === '1');
            toggle?.addEventListener('click', () => {
                const collapsed = !document.body.classList.contains('sidebar-collapsed');
                localStorage.setItem('controleSidebarCollapsed', collapsed ? '1' : '0');
                apply(collapsed);
            });
        }

        function runInlineScripts(root) {
            root.querySelectorAll('script').forEach((oldScript) => {
                const script = document.createElement('script');
                Array.from(oldScript.attributes).forEach((attr) => script.setAttribute(attr.name, attr.value));
                script.textContent = oldScript.src ? oldScript.textContent : `(() => {\n${oldScript.textContent}\n})();`;
                oldScript.replaceWith(script);
            });
        }

        function setActiveNav(url) {
            const target = new URL(url, window.location.href);
            document.querySelectorAll('.nav-item[href]').forEach((item) => {
                const itemUrl = new URL(item.getAttribute('href'), window.location.href);
                const active = itemUrl.pathname.split('/').pop() === target.pathname.split('/').pop();
                item.classList.toggle('active', active);
                item.classList.toggle('muted', !active);
            });
        }

        async function loadAdminPage(url, push = true) {
            const targetUrl = new URL(url, window.location.href);
            if (targetUrl.pathname.endsWith('/dashboard.php')) { window.location.href = targetUrl.href; return false; }
            const page = document.querySelector('.page');
            if (!page) return false;
            page.classList.add('is-loading');
            try {
                const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok) throw new Error('Falha ao carregar página');
                const html = await response.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const fragment = doc.querySelector('.admin-fragment, .page, .content');
                if (!fragment) throw new Error('Conteúdo não encontrado');
                page.innerHTML = fragment.innerHTML;
                page.dataset.pageTitle = fragment.dataset.pageTitle || doc.title.replace(' - Controle Big TI', '') || '';
                document.title = `${page.dataset.pageTitle || 'Controle Big TI'} - Controle Big TI`;
                if (push) history.pushState({ adminSpa: true }, '', url);
                setActiveNav(url);
                prepareResponsiveTables(page);
                runInlineScripts(page);
                requestAnimationFrame(() => page.classList.remove('is-loading'));
                return true;
            } catch (error) {
                window.location.href = url;
                return false;
            }
        }

        prepareResponsiveTables();
        setupSidebarCollapse();
        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[href]');
            if (!link || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            if (link.target && link.target !== '_self') return;
            const url = new URL(link.href, window.location.href);
            if (url.origin !== window.location.origin || !url.pathname.includes('/admin/') || url.pathname.endsWith('logout.php')) return;
            if (url.hash && url.pathname === window.location.pathname && url.search === window.location.search) return;
            event.preventDefault();
            loadAdminPage(url.href);
        });
        window.addEventListener('popstate', () => loadAdminPage(window.location.href, false));
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
