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

$dados = getConnection()->query($sql)->fetchAll();

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
</section>
<?php adminPageEnd(); ?>
