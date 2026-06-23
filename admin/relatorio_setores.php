<?php
require_once __DIR__ . '/admin_helpers.php';

$sql = "
    SELECT
        s.nome AS setor,
        COALESCE(mov.quantidade_entregas, 0) AS quantidade_entregas,
        COALESCE(mov.quantidade_trocas, 0) AS quantidade_trocas,
        COALESCE(mov.quantidade_equipamentos, 0) AS quantidade_equipamentos
    FROM setores s
    LEFT JOIN (
        SELECT
            setor_id,
            SUM(CASE WHEN UPPER(tipo) = 'ENTREGA' THEN 1 ELSE 0 END) AS quantidade_entregas,
            SUM(CASE WHEN UPPER(tipo) = 'TROCA' THEN 1 ELSE 0 END) AS quantidade_trocas,
            SUM(quantidade) AS quantidade_equipamentos
        FROM movimentacoes
        GROUP BY setor_id
    ) mov ON mov.setor_id = s.id
    WHERE COALESCE(mov.quantidade_equipamentos, 0) > 0
       OR COALESCE(mov.quantidade_entregas, 0) > 0
       OR COALESCE(mov.quantidade_trocas, 0) > 0
    ORDER BY s.nome
";

$limitesPermitidos = [5, 10, 20, 30, 50];
$porPagina = (int) ($_GET['limite'] ?? 5);
$porPagina = in_array($porPagina, $limitesPermitidos, true) ? $porPagina : 5;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$todosDados = getConnection()->query($sql)->fetchAll();
$totalRegistros = count($todosDados);
$totalPaginas = max(1, (int) ceil($totalRegistros / $porPagina));
$pagina = min($pagina, $totalPaginas);
$dados = array_slice($todosDados, ($pagina - 1) * $porPagina, $porPagina);

adminPageStart('Equipamentos por Setor');
?>
<section class="top">
    <div>
        <h1>Equipamentos por Setor</h1>
        <p>Distribuição real de equipamentos e movimentações por setor.</p>
    </div>
    <div>
        <button class="btn" type="button" disabled>Exportar</button>
        <a class="btn" href="dashboard.php">Voltar</a>
    </div>
</section>
<form class="panel filters" method="GET">
    <label class="limit-control" aria-label="Quantidade de registros"><span class="filter-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 5h18l-7 8v5l-4 2v-7L3 5Z"/></svg></span><select name="limite" onchange="this.form.submit()">
        <?php foreach ($limitesPermitidos as $limite): ?>
            <option value="<?php echo $limite; ?>" <?php echo $porPagina === $limite ? 'selected' : ''; ?>><?php echo $limite; ?></option>
        <?php endforeach; ?>
    </select></label>
    <button class="btn primary" type="submit">Aplicar</button>
</form>

<section class="panel">
    <div class="actions-note">Estrutura preparada para exportação futura.</div>
    <?php if (empty($dados)): ?>
        <div class="empty">Nenhum dado encontrado.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Setor</th>
                        <th>Quantidade de equipamentos</th>
                        <th>Quantidade de entregas</th>
                        <th>Quantidade de trocas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dados as $row): ?>
                        <tr>
                            <td><?php echo e($row['setor']); ?></td>
                            <td><?php echo (int) $row['quantidade_equipamentos']; ?></td>
                            <td><?php echo (int) $row['quantidade_entregas']; ?></td>
                            <td><?php echo (int) $row['quantidade_trocas']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if ($totalPaginas > 1): ?>
        <nav class="pagination" aria-label="Paginação">
            <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                <?php if ($i === $pagina): ?><span class="active"><?php echo $i; ?></span>
                <?php else: ?><a href="<?php echo e(pageUrl(['pagina' => $i])); ?>"><?php echo $i; ?></a><?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?></section>
<?php adminPageEnd(); ?>
