<?php

declare(strict_types=1);

initSecureSession();
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$mode = $_GET['mode'] ?? 'ibovespa';

try {
    if ($mode === 'auth_status') {
        echo json_encode([
            'authenticated' => isAuthenticated(),
            'user' => getCurrentUser(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'login') {
        requirePost();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = trim((string) ($_POST['password'] ?? ''));
        $user = tryLogin($username, $password);
        if (!$user) {
            throw new RuntimeException('Login invÃ¡lido.');
        }
        $_SESSION['user'] = $user;
        echo json_encode(['ok' => true, 'user' => $user], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'login_redirect') {
        requirePost();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = trim((string) ($_POST['password'] ?? ''));
        $user = tryLogin($username, $password);
        if (!$user) {
            header('Content-Type: text/html; charset=utf-8', true);
            http_response_code(401);
            echo '<!DOCTYPE html><html lang="pt-BR"><meta charset="utf-8"><title>Login</title><body style="font-family:Segoe UI,sans-serif;padding:24px;">Login inválido.<br><a href="./">Voltar</a></body></html>';
            exit;
        }
        $_SESSION['user'] = $user;
        header('Location: ./');
        exit;
    }

    if ($mode === 'logout') {
        $_SESSION = [];
        session_destroy();
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'users_list') {
        requireAuth();
        echo json_encode(['users' => listAppUsers()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'style_profile_get') {
        requireAuth();
        echo json_encode(['profile' => getStyleProfile()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'style_profile_save') {
        requireAuth();
        requirePost();
        $favoriteWords = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['favorite_words'] ?? '')))));
        $avoidWords = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['avoid_words'] ?? '')))));
        $profile = [
            'assistant_name' => trim((string) ($_POST['assistant_name'] ?? 'Assistente do Tiago')),
            'description' => trim((string) ($_POST['description'] ?? 'Perfil pessoal do Tiago')),
            'opening_style' => trim((string) ($_POST['opening_style'] ?? 'Fala, tudo certo?')),
            'closing_style' => trim((string) ($_POST['closing_style'] ?? 'Ja deixei anotado. Te retorno.')),
            'favorite_words' => $favoriteWords,
            'avoid_words' => $avoidWords,
            'notes' => trim((string) ($_POST['notes'] ?? '')),
            'sample_messages' => trim((string) ($_POST['sample_messages'] ?? '')),
            'updated_at' => gmdate('c'),
        ];
        saveStyleProfile($profile);
        echo json_encode(['ok' => true, 'profile' => getStyleProfile()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'backup_export') {
        requireAuth();
        requireAdmin();
        echo json_encode(exportFullBackup(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'backup_import') {
        requireAuth();
        requireAdmin();
        requirePost();
        $rawPayload = (string) ($_POST['payload'] ?? '');
        if ($rawPayload === '') {
            throw new RuntimeException('Informe o arquivo de backup.');
        }
        $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        $result = importFullBackup($payload);
        echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'user_create') {
        requireAuth();
        requireAdmin();
        requirePost();
        $name = trim((string) ($_POST['name'] ?? ''));
        $shortName = trim((string) ($_POST['short_name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = trim((string) ($_POST['password'] ?? ''));
        $user = createAppUser($name, $shortName, $username, $password);
        echo json_encode(['ok' => true, 'user' => $user], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'user_change_password') {
        requireAuth();
        requirePost();
        $currentPassword = trim((string) ($_POST['current_password'] ?? ''));
        $newPassword = trim((string) ($_POST['new_password'] ?? ''));
        changeCurrentUserPassword($currentPassword, $newPassword);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'ibovespa') {
        $dataset = getIbovespaDataset();
        echo json_encode(calculateMetrics($dataset), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'assets') {
        $rawSymbols = trim((string) ($_GET['symbols'] ?? ''));
        if ($rawSymbols === '') {
            throw new RuntimeException('Informe ao menos uma aÃ§Ã£o.');
        }

        $symbols = array_values(array_filter(array_map('trim', explode(',', $rawSymbols))));
        $assets = [];
        foreach (array_slice($symbols, 0, 8) as $symbol) {
            $assets[] = fetchAssetForPosition($symbol);
        }

        echo json_encode(['assets' => $assets], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'quote') {
        $symbol = trim((string) ($_GET['symbol'] ?? ''));
        if ($symbol === '') {
            throw new RuntimeException('Informe o cÃ³digo da aÃ§Ã£o.');
        }

        $dataset = fetchAssetHistory($symbol);
        echo json_encode(buildQuotePayload($dataset), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'opportunities') {
        $assets = [];
        foreach (getOpportunityUniverse() as $symbol) {
            try {
                $assets[] = fetchAssetForPosition($symbol);
            } catch (Throwable $ignored) {
            }
        }

        if (!$assets) {
            throw new RuntimeException('NÃ£o foi possÃ­vel montar o radar de oportunidades agora.');
        }

        foreach ($assets as &$asset) {
            $asset['opportunity_score'] = scoreOpportunity($asset);
        }
        unset($asset);

        usort($assets, static fn(array $a, array $b): int => $b['opportunity_score'] <=> $a['opportunity_score']);
        $mostActive = $assets;
        usort($mostActive, static fn(array $a, array $b): int => ((int) ($b['stats']['today_volume'] ?? 0)) <=> ((int) ($a['stats']['today_volume'] ?? 0)));
        $dayWorst = $assets;
        usort($dayWorst, static fn(array $a, array $b): int => ((float) ($a['day_change_pct'] ?? 0)) <=> ((float) ($b['day_change_pct'] ?? 0)));

        echo json_encode([
            'as_of' => gmdate('c'),
            'universe_note' => 'Radar calculado em aÃ§Ãµes lÃ­quidas do universo do Ibovespa B3.',
            'best' => array_values(array_slice($assets, 0, 10)),
            'worst' => array_values(array_slice(array_reverse($assets), 0, 10)),
            'most_active' => array_values(array_slice($mostActive, 0, 10)),
            'day_worst' => array_values(array_slice($dayWorst, 0, 10)),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'crypto_assets') {
        requireAuth();
        $assets = [];
        foreach (getCryptoUniverse() as $symbol) {
            try {
                $asset = fetchAssetForPosition($symbol);
                $asset['catalog'] = [
                    'symbol' => $symbol,
                    'type' => 'Cripto',
                    'sector' => 'Cripto moedas',
                ];
                $assets[] = $asset;
            } catch (Throwable $ignored) {
            }
        }

        echo json_encode([
            'items' => $assets,
            'updated_at' => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'stock_screener') {
        requireAuth();
        $query = trim((string) ($_GET['q'] ?? ''));
        $sectorName = trim((string) ($_GET['sector_name'] ?? ''));
        $sector = trim((string) ($_GET['sector'] ?? ''));
        $type = trim((string) ($_GET['type'] ?? ''));
        $dividendsOnly = (string) ($_GET['dividends'] ?? '') === '1';
        $defensiveOnly = (string) ($_GET['defensive'] ?? '') === '1';
        $trendOnly = (string) ($_GET['trend'] ?? '') === '1';
        $sort = trim((string) ($_GET['sort'] ?? 'score'));

        $catalog = getStockCatalog();
        $matched = [];
        foreach ($catalog as $item) {
            $haystack = strtoupper($item['symbol'] . ' ' . $item['name'] . ' ' . $item['sector'] . ' ' . $item['type']);
            if ($query !== '' && !str_contains($haystack, strtoupper($query))) {
                continue;
            }
            if ($sectorName !== '' && !str_contains(strtoupper($item['sector']), strtoupper($sectorName))) {
                continue;
            }
            if ($sector !== '' && $item['sector'] !== $sector) {
                continue;
            }
            if ($type !== '' && $item['type'] !== $type) {
                continue;
            }
            if ($dividendsOnly && !$item['pays_dividends']) {
                continue;
            }
            if ($defensiveOnly && !$item['defensive']) {
                continue;
            }
            $matched[] = $item;
        }

        $items = [];
        foreach (array_slice($matched, 0, 12) as $meta) {
            try {
                $asset = fetchAssetForPosition($meta['symbol']);
                if ($trendOnly && (float) ($asset['stats']['momentum_20d'] ?? 0.0) <= 0) {
                    continue;
                }
                $asset['catalog'] = $meta;
                $asset['opportunity_score'] = scoreOpportunity($asset);
                $items[] = $asset;
            } catch (Throwable $ignored) {
            }
        }

        usort($items, static function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'day_change' => ((float) ($b['day_change_pct'] ?? 0.0)) <=> ((float) ($a['day_change_pct'] ?? 0.0)),
                'volatility' => ((float) ($a['stats']['daily_volatility'] ?? 0.0)) <=> ((float) ($b['stats']['daily_volatility'] ?? 0.0)),
                'dividend' => ((int) (($b['catalog']['pays_dividends'] ?? false) ? 1 : 0)) <=> ((int) (($a['catalog']['pays_dividends'] ?? false) ? 1 : 0)),
                'price' => ((float) ($b['current_price'] ?? 0.0)) <=> ((float) ($a['current_price'] ?? 0.0)),
                default => ((float) ($b['opportunity_score'] ?? 0.0)) <=> ((float) ($a['opportunity_score'] ?? 0.0)),
            };
        });

        echo json_encode([
            'items' => $items,
            'meta' => [
                'query' => $query,
                'sector_name' => $sectorName,
                'total_catalog' => count($catalog),
                'matched_catalog' => count($matched),
                'loaded_items' => count($items),
                'sectors' => array_values(array_unique(array_map(static fn(array $item): string => $item['sector'], $catalog))),
                'types' => array_values(array_unique(array_map(static fn(array $item): string => $item['type'], $catalog))),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'portfolio_list') {
        requireAuth();
        echo json_encode([
            'positions' => loadPortfolioPositions(),
            'archived' => loadArchivedPortfolioPositions(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'portfolio_joint') {
        requireAuth();
        echo json_encode(loadJointPortfolioPayload(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'portfolio_add') {
        requireAuth();
        requirePost();
        $symbol = trim((string) ($_POST['symbol'] ?? ''));
        $assetType = normalizePortfolioAssetType((string) ($_POST['asset_type'] ?? 'acao'));
        $purchaseDate = trim((string) ($_POST['purchase_date'] ?? ''));
        $quantity = (float) ($_POST['quantity'] ?? 0);
        $purchasePrice = (float) ($_POST['purchase_price'] ?? 0);
        $manualCurrentValue = (float) ($_POST['manual_current_value'] ?? 0);
        $targetOwner = resolvePortfolioOwnerTarget((string) ($_POST['owner'] ?? ''));

        if ($symbol === '' || $purchaseDate === '' || $quantity <= 0 || $purchasePrice <= 0) {
            throw new RuntimeException('Preencha data, quantidade, cÃ³digo e valor pago.');
        }

        $asset = fetchAssetForPosition($symbol, $assetType, $manualCurrentValue);
        $pdo = getDb();
        $stmt = $pdo->prepare(
            'INSERT INTO portfolio_positions (purchase_date, quantity, symbol, purchase_price, manual_current_value, asset_type, owner, created_at)
             VALUES (:purchase_date, :quantity, :symbol, :purchase_price, :manual_current_value, :asset_type, :owner, :created_at)'
        );
        $stmt->execute([
            ':purchase_date' => $purchaseDate,
            ':quantity' => $quantity,
            ':symbol' => $asset['symbol'],
            ':purchase_price' => $purchasePrice,
            ':manual_current_value' => $manualCurrentValue > 0 ? $manualCurrentValue : null,
            ':asset_type' => $assetType,
            ':owner' => $targetOwner,
            ':created_at' => gmdate('c'),
        ]);

        echo json_encode([
            'ok' => true,
            'position_id' => (int) $pdo->lastInsertId(),
            'position' => buildPortfolioPosition((int) $pdo->lastInsertId(), $purchaseDate, $quantity, $asset['symbol'], $purchasePrice, $assetType, $manualCurrentValue),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'portfolio_delete') {
        requireAuth();
        requirePost();
        requireDeletePassword();
        $id = (int) ($_POST['id'] ?? 0);
        $targetOwner = resolvePortfolioOwnerTarget((string) ($_POST['owner'] ?? ''));
        if ($id <= 0) {
            throw new RuntimeException('PosiÃ§Ã£o invÃ¡lida.');
        }

        $stmt = getDb()->prepare('DELETE FROM portfolio_positions WHERE id = :id AND owner = :owner');
        $stmt->execute([
            ':id' => $id,
            ':owner' => $targetOwner,
        ]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'portfolio_update') {
        requireAuth();
        requirePost();
        $id = (int) ($_POST['id'] ?? 0);
        $targetOwner = resolvePortfolioOwnerTarget((string) ($_POST['owner'] ?? ''));
        $symbol = trim((string) ($_POST['symbol'] ?? ''));
        $assetType = normalizePortfolioAssetType((string) ($_POST['asset_type'] ?? 'acao'));
        $purchaseDate = trim((string) ($_POST['purchase_date'] ?? ''));
        $quantity = (float) ($_POST['quantity'] ?? 0);
        $purchasePrice = (float) ($_POST['purchase_price'] ?? 0);
        $manualCurrentValue = (float) ($_POST['manual_current_value'] ?? 0);
        if ($id <= 0 || $symbol === '' || $purchaseDate === '' || $quantity <= 0 || $purchasePrice <= 0) {
            throw new RuntimeException('Preencha os dados da posiÃ§Ã£o.');
        }
        $asset = fetchAssetForPosition($symbol, $assetType, $manualCurrentValue);
        $stmt = getDb()->prepare(
            'UPDATE portfolio_positions
             SET purchase_date = :purchase_date, quantity = :quantity, symbol = :symbol, purchase_price = :purchase_price, manual_current_value = :manual_current_value, asset_type = :asset_type
             WHERE id = :id AND owner = :owner'
        );
        $stmt->execute([
            ':purchase_date' => $purchaseDate,
            ':quantity' => $quantity,
            ':symbol' => $asset['symbol'],
            ':purchase_price' => $purchasePrice,
            ':manual_current_value' => $manualCurrentValue > 0 ? $manualCurrentValue : null,
            ':asset_type' => $assetType,
            ':id' => $id,
            ':owner' => $targetOwner,
        ]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'portfolio_archive') {
        requireAuth();
        requirePost();
        $id = (int) ($_POST['id'] ?? 0);
        $targetOwner = resolvePortfolioOwnerTarget((string) ($_POST['owner'] ?? ''));
        if ($id <= 0) {
            throw new RuntimeException('PosiÃ§Ã£o invÃ¡lida.');
        }
        $stmt = getDb()->prepare(
            'UPDATE portfolio_positions
             SET archived = 1
             WHERE id = :id AND owner = :owner'
        );
        $stmt->execute([
            ':id' => $id,
            ':owner' => $targetOwner,
        ]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'portfolio_unarchive') {
        requireAuth();
        requirePost();
        $id = (int) ($_POST['id'] ?? 0);
        $targetOwner = resolvePortfolioOwnerTarget((string) ($_POST['owner'] ?? ''));
        if ($id <= 0) {
            throw new RuntimeException('PosiÃ§Ã£o invÃ¡lida.');
        }
        $stmt = getDb()->prepare(
            'UPDATE portfolio_positions
             SET archived = 0
             WHERE id = :id AND owner = :owner'
        );
        $stmt->execute([
            ':id' => $id,
            ':owner' => $targetOwner,
        ]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'portfolio_sell') {
        requireAuth();
        requirePost();
        $id = (int) ($_POST['id'] ?? 0);
        $saleDate = trim((string) ($_POST['sale_date'] ?? ''));
        $quantity = (float) ($_POST['quantity'] ?? 0);
        $salePrice = (float) ($_POST['sale_price'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $owner = resolvePortfolioOwnerTarget((string) ($_POST['owner'] ?? ''));

        if ($id <= 0 || $saleDate === '' || $quantity <= 0 || $salePrice <= 0) {
            throw new RuntimeException('Preencha data, quantidade e valor da venda.');
        }

        $stmt = getDb()->prepare('SELECT * FROM portfolio_positions WHERE id = :id AND owner = :owner');
        $stmt->execute([
            ':id' => $id,
            ':owner' => $owner,
        ]);
        $position = $stmt->fetch();
        if (!$position) {
            throw new RuntimeException('Somente o dono da ação pode registrar a venda.');
        }

        $currentQuantity = (float) ($position['quantity'] ?? 0);
        if ($quantity > $currentQuantity) {
            throw new RuntimeException('A quantidade vendida não pode ser maior que a quantidade atual.');
        }

        $pdo = getDb();
        $pdo->beginTransaction();
        try {
            $saleStmt = $pdo->prepare(
                'INSERT INTO portfolio_sales (position_id, owner, symbol, sale_date, quantity, sale_price, notes, created_at)
                 VALUES (:position_id, :owner, :symbol, :sale_date, :quantity, :sale_price, :notes, :created_at)'
            );
            $saleStmt->execute([
                ':position_id' => $id,
                ':owner' => $owner,
                ':symbol' => (string) ($position['symbol'] ?? ''),
                ':sale_date' => $saleDate,
                ':quantity' => $quantity,
                ':sale_price' => $salePrice,
                ':notes' => $notes,
                ':created_at' => gmdate('c'),
            ]);

            $remainingQuantity = $currentQuantity - $quantity;
            if ($remainingQuantity <= 0) {
                $deleteStmt = $pdo->prepare('DELETE FROM portfolio_positions WHERE id = :id AND owner = :owner');
                $deleteStmt->execute([
                    ':id' => $id,
                    ':owner' => $owner,
                ]);
            } else {
                $updateStmt = $pdo->prepare(
                    'UPDATE portfolio_positions
                     SET quantity = :quantity
                     WHERE id = :id AND owner = :owner'
                );
                $updateStmt->execute([
                    ':quantity' => $remainingQuantity,
                    ':id' => $id,
                    ':owner' => $owner,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'finance_list') {
        requireAuth();
        echo json_encode(getFinancePayload(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'finance_add') {
        requireAuth();
        requirePost();
        $type = trim((string) ($_POST['type'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $amount = (float) ($_POST['amount'] ?? 0);
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'programado'));
        $recurrence = trim((string) ($_POST['recurrence'] ?? 'unico'));
        $installments = max(1, (int) ($_POST['installments'] ?? 1));
        $actor = trim((string) ($_POST['actor'] ?? (getCurrentUser()['short_name'] ?? 'Tiago')));
        $createdBy = trim((string) ($_POST['created_by'] ?? $actor));
        $expenseTag = trim((string) ($_POST['expense_tag'] ?? 'variavel'));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($type === '' || $category === '' || $description === '' || $amount <= 0 || $dueDate === '') {
            throw new RuntimeException('Preencha tipo, categoria, descriÃ§Ã£o, valor e data.');
        }

        $type = normalizeFinanceType($type, $category);

        $stmt = getDb()->prepare(
            'INSERT INTO finance_entries (type, category, description, amount, due_date, status, recurrence, installments_total, installment_number, actor, updated_by, updated_at, notes, created_at)
             VALUES (:type, :category, :description, :amount, :due_date, :status, :recurrence, :installments_total, :installment_number, :actor, :updated_by, :updated_at, :notes, :created_at)'
        );
        for ($i = 1; $i <= $installments; $i++) {
            $stmt->execute([
                ':type' => $type,
                ':category' => $category,
                ':description' => $description . ($installments > 1 ? ' ' . $i . '/' . $installments : ''),
                ':amount' => $amount,
                ':due_date' => shiftMonths($dueDate, $i - 1),
                ':status' => $status,
                ':recurrence' => $recurrence,
                ':installments_total' => $installments,
                ':installment_number' => $i,
                ':actor' => $actor,
                ':updated_by' => $actor,
                ':updated_at' => gmdate('c'),
                ':notes' => $notes,
                ':created_at' => gmdate('c'),
            ]);
            getDb()->prepare('UPDATE finance_entries SET created_by = :created_by, expense_tag = :expense_tag WHERE id = :id')->execute([
                ':created_by' => $createdBy,
                ':expense_tag' => normalizeExpenseTag($expenseTag),
                ':id' => (int) getDb()->lastInsertId(),
            ]);
        }

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'finance_delete') {
        requireAuth();
        requirePost();
        requireDeletePassword();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('LanÃ§amento invÃ¡lido.');
        }
        $stmt = getDb()->prepare('DELETE FROM finance_entries WHERE id = :id');
        $stmt->execute([':id' => $id]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'finance_update') {
        requireAuth();
        requirePost();
        $id = (int) ($_POST['id'] ?? 0);
        $type = trim((string) ($_POST['type'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $amount = (float) ($_POST['amount'] ?? 0);
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'programado'));
        $recurrence = trim((string) ($_POST['recurrence'] ?? 'unico'));
        $actor = trim((string) ($_POST['actor'] ?? (getCurrentUser()['short_name'] ?? 'Tiago')));
        $createdBy = trim((string) ($_POST['created_by'] ?? $actor));
        $expenseTag = trim((string) ($_POST['expense_tag'] ?? 'variavel'));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if ($id <= 0 || $type === '' || $category === '' || $description === '' || $amount <= 0 || $dueDate === '') {
            throw new RuntimeException('Preencha os dados do lanÃ§amento.');
        }
        $type = normalizeFinanceType($type, $category);

        $stmt = getDb()->prepare(
              'UPDATE finance_entries
               SET type = :type, category = :category, description = :description, amount = :amount, due_date = :due_date,
                  status = :status, recurrence = :recurrence, actor = :actor, created_by = :created_by, expense_tag = :expense_tag, updated_by = :updated_by, updated_at = :updated_at, notes = :notes
               WHERE id = :id'
          );
          $stmt->execute([
              ':type' => $type,
              ':category' => $category,
            ':description' => $description,
            ':amount' => $amount,
              ':due_date' => $dueDate,
              ':status' => $status,
              ':recurrence' => $recurrence,
              ':actor' => $actor,
              ':created_by' => $createdBy,
              ':expense_tag' => normalizeExpenseTag($expenseTag),
              ':updated_by' => $actor,
              ':updated_at' => gmdate('c'),
              ':notes' => $notes,
              ':id' => $id,
          ]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'finance_service_add') {
        requireAuth();
        requirePost();
        $name = trim((string) ($_POST['nome_cliente'] ?? ''));
        $value = (float) ($_POST['valor'] ?? 0);
        $dueDay = (int) ($_POST['dia_vencimento'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? 'pendente'));
        $userId = trim((string) ($_POST['usuario_id'] ?? (getCurrentUser()['id'] ?? 'tiago')));
        $createdBy = trim((string) ($_POST['created_by'] ?? (getCurrentUser()['short_name'] ?? 'Tiago')));

        if ($name === '' || $value <= 0 || $dueDay < 1 || $dueDay > 31) {
            throw new RuntimeException('Preencha cliente, valor e dia do vencimento.');
        }

        $stmt = getDb()->prepare(
            'INSERT INTO finance_services (nome_cliente, valor, dia_vencimento, status, usuario_id, created_by, updated_at, created_at)
             VALUES (:nome_cliente, :valor, :dia_vencimento, :status, :usuario_id, :created_by, :updated_at, :created_at)'
        );
        $stmt->execute([
            ':nome_cliente' => $name,
            ':valor' => $value,
            ':dia_vencimento' => $dueDay,
            ':status' => $status === 'pago' ? 'pago' : 'pendente',
            ':usuario_id' => $userId,
            ':created_by' => $createdBy,
            ':updated_at' => gmdate('c'),
            ':created_at' => gmdate('c'),
        ]);

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'finance_service_update') {
        requireAuth();
        requirePost();
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['nome_cliente'] ?? ''));
        $value = (float) ($_POST['valor'] ?? 0);
        $dueDay = (int) ($_POST['dia_vencimento'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? 'pendente'));
        $createdBy = trim((string) ($_POST['created_by'] ?? (getCurrentUser()['short_name'] ?? 'Tiago')));

        if ($id <= 0 || $name === '' || $value <= 0 || $dueDay < 1 || $dueDay > 31) {
            throw new RuntimeException('Preencha os dados do serviço mensal.');
        }

        $stmt = getDb()->prepare(
            'UPDATE finance_services
             SET nome_cliente = :nome_cliente, valor = :valor, dia_vencimento = :dia_vencimento, status = :status, created_by = :created_by, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':nome_cliente' => $name,
            ':valor' => $value,
            ':dia_vencimento' => $dueDay,
            ':status' => $status === 'pago' ? 'pago' : 'pendente',
            ':created_by' => $createdBy,
            ':updated_at' => gmdate('c'),
            ':id' => $id,
        ]);

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'finance_service_delete') {
        requireAuth();
        requirePost();
        requireDeletePassword();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Serviço mensal inválido.');
        }
        $stmt = getDb()->prepare('DELETE FROM finance_services WHERE id = :id');
        $stmt->execute([':id' => $id]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'finance_service_check') {
        requireAuth();
        requirePost();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Serviço mensal inválido.');
        }
        $currentMonth = date('Y-m');
        $stmt = getDb()->prepare(
            'UPDATE finance_services
             SET status = :status, last_paid_month = :last_paid_month, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => 'pago',
            ':last_paid_month' => $currentMonth,
            ':updated_at' => gmdate('c'),
            ':id' => $id,
        ]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'finance_cash_reset_request') {
        requireAuth();
        requirePost();
        echo json_encode(['ok' => true, 'request' => requestFinanceCashReset(trim((string) ($_POST['notes'] ?? '')))], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'finance_cash_reset_confirm') {
        requireAuth();
        requirePost();
        $id = (int) ($_POST['id'] ?? 0);
        echo json_encode(['ok' => true, 'request' => confirmFinanceCashReset($id)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'finance_cash_reset_execute') {
        requireAuth();
        requirePost();
        $id = (int) ($_POST['id'] ?? 0);
        echo json_encode(['ok' => true, 'result' => executeFinanceCashReset($id)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'finance_cash_reset_cancel') {
        requireAuth();
        requirePost();
        $id = (int) ($_POST['id'] ?? 0);
        echo json_encode(['ok' => true, 'request' => cancelFinanceCashReset($id)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'family_list') {
        requireAuth();
        echo json_encode(getFamilyPayloadV2(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'family_add') {
        requireAuth();
        requirePost();
        $member = trim((string) ($_POST['member'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? 'rotina'));
        $dueDate = trim((string) ($_POST['due_date'] ?? date('Y-m-d')));
        $dueTime = trim((string) ($_POST['due_time'] ?? ''));
        $startTime = trim((string) ($_POST['hora_inicio'] ?? $dueTime));
        $endTime = trim((string) ($_POST['hora_fim'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'pendente'));
        $kanbanStatus = normalizeKanbanStatus((string) ($_POST['kanban_status'] ?? 'a_fazer'));
        $recurrence = trim((string) ($_POST['recurrence'] ?? 'unico'));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $urgent = (int) ((string) ($_POST['urgente'] ?? '0') === '1');
        $important = (int) ((string) ($_POST['importante'] ?? '0') === '1');
        $reminderEnabled = (int) ((string) ($_POST['reminder_enabled'] ?? '1') === '1');
        $reminderOffset = max(0, (int) ($_POST['reminder_offset_minutes'] ?? 30));
        $userId = (string) (getCurrentUser()['id'] ?? 'tiago');

        if ($member === '' || $title === '' || $dueDate === '') {
            throw new RuntimeException('Preencha membro, tarefa e data.');
        }

        assertAgendaAvailability($userId, $dueDate, $startTime, $endTime, 0);

        $stmt = getDb()->prepare(
            'INSERT INTO family_tasks (user_id, member, title, category, due_date, due_time, hora_inicio, hora_fim, status, kanban_status, urgente, importante, archived, recurrence, notes, reminder_enabled, reminder_offset_minutes, created_at)
             VALUES (:user_id, :member, :title, :category, :due_date, :due_time, :hora_inicio, :hora_fim, :status, :kanban_status, :urgente, :importante, 0, :recurrence, :notes, :reminder_enabled, :reminder_offset_minutes, :created_at)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':member' => $member,
            ':title' => $title,
            ':category' => $category,
            ':due_date' => $dueDate,
            ':due_time' => $dueTime,
            ':hora_inicio' => $startTime,
            ':hora_fim' => $endTime,
            ':status' => $status,
            ':kanban_status' => $kanbanStatus,
            ':urgente' => $urgent,
            ':importante' => $important,
            ':recurrence' => $recurrence,
            ':notes' => $notes,
            ':reminder_enabled' => $reminderEnabled,
            ':reminder_offset_minutes' => $reminderOffset,
            ':created_at' => gmdate('c'),
        ]);

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'family_delete') {
        requireAuth();
        requirePost();
        requireDeletePassword();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Tarefa invÃ¡lida.');
        }
        $stmt = getDb()->prepare('DELETE FROM family_tasks WHERE id = :id AND user_id = :user_id');
        $stmt->execute([
            ':id' => $id,
            ':user_id' => (string) (getCurrentUser()['id'] ?? 'tiago'),
        ]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'family_update') {
        requireAuth();
        requirePost();
        $id = (int) ($_POST['id'] ?? 0);
        $member = trim((string) ($_POST['member'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? 'rotina'));
        $dueDate = trim((string) ($_POST['due_date'] ?? date('Y-m-d')));
        $dueTime = trim((string) ($_POST['due_time'] ?? ''));
        $startTime = trim((string) ($_POST['hora_inicio'] ?? $dueTime));
        $endTime = trim((string) ($_POST['hora_fim'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'pendente'));
        $kanbanStatus = normalizeKanbanStatus((string) ($_POST['kanban_status'] ?? 'a_fazer'));
        $recurrence = trim((string) ($_POST['recurrence'] ?? 'unico'));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $urgent = (int) ((string) ($_POST['urgente'] ?? '0') === '1');
        $important = (int) ((string) ($_POST['importante'] ?? '0') === '1');
        $reminderEnabled = (int) ((string) ($_POST['reminder_enabled'] ?? '1') === '1');
        $reminderOffset = max(0, (int) ($_POST['reminder_offset_minutes'] ?? 30));
        $userId = (string) (getCurrentUser()['id'] ?? 'tiago');
        if ($id <= 0 || $member === '' || $title === '' || $dueDate === '') {
            throw new RuntimeException('Preencha os dados da tarefa.');
        }
        assertAgendaAvailability($userId, $dueDate, $startTime, $endTime, $id);
        $stmt = getDb()->prepare(
            'UPDATE family_tasks
             SET member = :member, title = :title, category = :category, due_date = :due_date,
                 due_time = :due_time, hora_inicio = :hora_inicio, hora_fim = :hora_fim, status = :status,
                 kanban_status = :kanban_status, urgente = :urgente, importante = :importante, recurrence = :recurrence, notes = :notes,
                 reminder_enabled = :reminder_enabled, reminder_offset_minutes = :reminder_offset_minutes
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            ':member' => $member,
            ':title' => $title,
            ':category' => $category,
            ':due_date' => $dueDate,
            ':due_time' => $dueTime,
            ':hora_inicio' => $startTime,
            ':hora_fim' => $endTime,
            ':status' => $status,
            ':kanban_status' => $kanbanStatus,
            ':urgente' => $urgent,
            ':importante' => $important,
            ':recurrence' => $recurrence,
            ':notes' => $notes,
            ':reminder_enabled' => $reminderEnabled,
            ':reminder_offset_minutes' => $reminderOffset,
            ':id' => $id,
            ':user_id' => $userId,
        ]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'family_toggle') {
        requireAuth();
        requirePost();
        $id = (int) ($_POST['id'] ?? 0);
        $status = normalizeKanbanStatus((string) ($_POST['status'] ?? 'feito'));
        if ($id <= 0) {
            throw new RuntimeException('Tarefa invÃ¡lida.');
        }
        $task = getFamilyTaskById($id);
        if (!$task || (string) ($task['user_id'] ?? '') !== (string) (getCurrentUser()['id'] ?? 'tiago')) {
            throw new RuntimeException('Tarefa invÃƒÂ¡lida.');
        }
        $archive = $status === 'feito' && shouldArchiveQuickTask($task);
        $stmt = getDb()->prepare('UPDATE family_tasks SET status = :legacy_status, kanban_status = :status, archived = :archived WHERE id = :id AND user_id = :user_id');
        $stmt->execute([
            ':legacy_status' => $status === 'feito' ? 'concluido' : 'pendente',
            ':status' => $status,
            ':archived' => $archive ? 1 : 0,
            ':id' => $id,
            ':user_id' => (string) (getCurrentUser()['id'] ?? 'tiago'),
        ]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'tit_messages') {
        requireAuth();
        echo json_encode(getTitMessagesPayload(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'tit_message_add') {
        requireAuth();
        requirePost();
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($message === '') {
            throw new RuntimeException('Digite uma mensagem.');
        }
        addTitMessage($message);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'notifications_list') {
        requireAuth();
        echo json_encode(getNotificationsPayload(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'notification_settings_update') {
        requireAuth();
        requirePost();
        echo json_encode([
            'ok' => true,
            'settings' => updateNotificationSettings($_POST),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'notification_test') {
        requireAuth();
        requirePost();
        $user = getCurrentUser();
        addNotification(
            (string) ($user['id'] ?? 'tiago'),
            'Teste de notificacao',
            'Seu sistema de notificacoes esta ativo e configurado.',
            'info',
            'notification_test',
            0,
            'low'
        );
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'notification_read') {
        requireAuth();
        requirePost();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            markNotificationRead($id);
        }
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'notifications_read_all') {
        requireAuth();
        requirePost();
        markAllNotificationsRead();
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Modo nÃ£o encontrado.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $message = $e->getMessage();
    $status = str_contains(mb_strtolower($message), 'consultar') ? 502 : 500;
    http_response_code($status);
    if (!isDebugMode()) {
        echo json_encode([
            'error' => 'NÃƒÂ£o foi possÃƒÂ­vel processar a solicitaÃƒÂ§ÃƒÂ£o.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'error' => 'NÃ£o foi possÃ­vel processar a solicitaÃ§Ã£o.',
        'details' => $message,
    ], JSON_UNESCAPED_UNICODE);
}

function initSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (isHttpsRequest()) {
        ini_set('session.cookie_secure', '1');
    }

    session_name('hub_pessoal_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function requirePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('RequisiÃ§Ã£o invÃ¡lida.');
    }
    if (!isSameOriginRequest()) {
        http_response_code(403);
        throw new RuntimeException('Origem invÃ¡lida para esta solicitaÃ§Ã£o.');
    }
}

function isHttpsRequest(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function isDebugMode(): bool
{
    $flag = strtolower((string) (getenv('APP_DEBUG') ?: ''));
    if (in_array($flag, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return str_contains($host, 'localhost') || str_contains($host, '127.0.0.1');
}

function isSameOriginRequest(): bool
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    $expectedHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

    foreach ([$origin, $referer] as $candidate) {
        if ($candidate === '') {
            continue;
        }
        $parts = parse_url($candidate);
        $candidateHost = strtolower((string) ($parts['host'] ?? ''));
        if ($candidateHost === '') {
            continue;
        }
        return $candidateHost === $expectedHost;
    }

    return true;
}

function getAppUsers(): array
{
    $rows = getDb()->query('SELECT id, username, name, short_name, password_hash, role, created_at FROM app_users ORDER BY id ASC')->fetchAll();
    return array_map(static fn(array $row): array => [
        'id' => (string) $row['id'],
        'username' => (string) $row['username'],
        'name' => (string) $row['name'],
        'short_name' => (string) $row['short_name'],
        'password_hash' => (string) $row['password_hash'],
        'role' => (string) ($row['role'] ?? 'user'),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ], $rows);
}

function tryLogin(string $username, string $password): ?array
{
    foreach (getAppUsers() as $user) {
        if (
            mb_strtolower($username) === mb_strtolower((string) ($user['username'] ?? '')) ||
            mb_strtolower($username) === mb_strtolower($user['name']) ||
            mb_strtolower($username) === mb_strtolower($user['id'])
        ) {
            if (password_verify($password, (string) ($user['password_hash'] ?? ''))) {
                unset($user['password_hash']);
                return $user;
            }
        }
    }
    return null;
}

function validateUserPassword(string $userId, string $password): bool
{
    foreach (getAppUsers() as $user) {
        if (($user['id'] ?? '') === $userId) {
            return password_verify($password, (string) ($user['password_hash'] ?? ''));
        }
    }
    return false;
}

function listAppUsers(): array
{
    return array_map(static function (array $user): array {
        unset($user['password_hash']);
        return $user;
    }, getAppUsers());
}

function createAppUser(string $name, string $shortName, string $username, string $password): array
{
    if ($name === '' || $shortName === '' || $username === '' || $password === '') {
        throw new RuntimeException('Preencha nome, nome curto, usu?rio e senha.');
    }
    $id = normalizeUserId($username);
    if ($id === '') {
        throw new RuntimeException('Usu?rio inv?lido.');
    }
    if (mb_strlen($password) < 4) {
        throw new RuntimeException('A senha deve ter ao menos 4 caracteres.');
    }

    $createdAt = gmdate('c');
    $stmt = getDb()->prepare(
        'INSERT INTO app_users (id, username, name, short_name, password_hash, role, created_at)
         VALUES (:id, :username, :name, :short_name, :password_hash, :role, :created_at)'
    );
    try {
        $stmt->execute([
            ':id' => $id,
            ':username' => mb_strtolower(trim($username)),
            ':name' => $name,
            ':short_name' => $shortName,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':role' => 'user',
            ':created_at' => $createdAt,
        ]);
    } catch (Throwable $error) {
        throw new RuntimeException('Usu?rio j? cadastrado.');
    }

    return [
        'id' => $id,
        'username' => mb_strtolower(trim($username)),
        'name' => $name,
        'short_name' => $shortName,
        'role' => 'user',
        'created_at' => $createdAt,
    ];
}

function changeCurrentUserPassword(string $currentPassword, string $newPassword): void
{
    $user = getCurrentUser();
    if (!$user) {
        throw new RuntimeException('Fa?a login novamente.');
    }
    if ($currentPassword === '' || $newPassword === '') {
        throw new RuntimeException('Informe a senha atual e a nova senha.');
    }
    if (!validateUserPassword((string) ($user['id'] ?? ''), $currentPassword)) {
        throw new RuntimeException('Senha atual incorreta.');
    }
    if (mb_strlen($newPassword) < 4) {
        throw new RuntimeException('A nova senha deve ter ao menos 4 caracteres.');
    }

    $stmt = getDb()->prepare('UPDATE app_users SET password_hash = :password_hash WHERE id = :id');
    $stmt->execute([
        ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ':id' => (string) ($user['id'] ?? ''),
    ]);
}

function normalizeUserId(string $value): string
{
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
    return trim($value, '_');
}

function getCurrentUser(): ?array
{
    $user = $_SESSION['user'] ?? null;
    return is_array($user) ? $user : null;
}

function isAuthenticated(): bool
{
    return getCurrentUser() !== null;
}

function requireAuth(): void
{
    if (!isAuthenticated()) {
        http_response_code(401);
        throw new RuntimeException('FaÃ§a login para acessar este mÃ³dulo.');
    }
}

function isAdminUser(?array $user = null): bool
{
    $user ??= getCurrentUser();
    if (!is_array($user)) {
        return false;
    }
    if (($user['role'] ?? '') === 'admin') {
        return true;
    }
    $userId = (string) ($user['id'] ?? '');
    if ($userId === '') {
        return false;
    }
    foreach (getAppUsers() as $dbUser) {
        if (($dbUser['id'] ?? '') === $userId) {
            return (($dbUser['role'] ?? 'user') === 'admin');
        }
    }
    return false;
}

function requireAdmin(): void
{
    if (!isAdminUser()) {
        http_response_code(403);
        throw new RuntimeException('Acesso restrito ao administrador.');
    }
}

function requireDeletePassword(): void
{
    $password = trim((string) ($_POST['password'] ?? ''));
    $user = getCurrentUser();
    if (!$user || $password === '') {
        throw new RuntimeException('Informe a senha para excluir.');
    }
    if (!validateUserPassword((string) ($user['id'] ?? ''), $password)) {
        throw new RuntimeException('Senha incorreta para excluir.');
    }
}

function getStorageRoot(): string
{
    $envPath = trim((string) (getenv('APP_STORAGE_DIR') ?: ''));
    if ($envPath !== '') {
        return rtrim($envPath, "\\/");
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app_storage';
}

function getLegacyPublicPath(string $relativePath): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
}

function getStoragePath(string $relativePath): string
{
    return getStorageRoot() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
}

function ensureStorageDirectory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Nao foi possivel preparar a pasta privada do aplicativo.');
    }
}

function migrateLegacyFileIfNeeded(string $relativePath): string
{
    $target = getStoragePath($relativePath);
    if (is_file($target)) {
        return $target;
    }

    $legacy = getLegacyPublicPath($relativePath);
    if (is_file($legacy)) {
        ensureStorageDirectory((string) dirname($target));
        copy($legacy, $target);
    }

    return $target;
}

function resolvePortfolioOwnerTarget(string $requestedOwner = ''): string
{
    $currentUser = getCurrentUser();
    $currentOwner = (string) ($currentUser['id'] ?? 'tiago');
    $requestedOwner = trim($requestedOwner);
    if ($requestedOwner === '' || $requestedOwner === $currentOwner) {
        return $currentOwner;
    }
    $sharedOwners = ['tiago', 'thais'];
    if (in_array($currentOwner, $sharedOwners, true) && in_array($requestedOwner, $sharedOwners, true)) {
        return $requestedOwner;
    }
    throw new RuntimeException('VocÃª nÃ£o pode alterar este ativo.');
}

function getDb(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dataDir = getStoragePath('data');
    ensureStorageDirectory($dataDir);
    $dbPath = $dataDir . DIRECTORY_SEPARATOR . 'app.db';
    $legacyDbPath = getLegacyPublicPath('data/app.db');
    if (!is_file($dbPath) && is_file($legacyDbPath)) {
        copy($legacyDbPath, $dbPath);
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS portfolio_positions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            purchase_date TEXT NOT NULL,
            quantity REAL NOT NULL,
            symbol TEXT NOT NULL,
            purchase_price REAL NOT NULL,
            manual_current_value REAL DEFAULT NULL,
            asset_type TEXT DEFAULT \'acao\',
            owner TEXT DEFAULT \'tiago\',
            archived INTEGER DEFAULT 0,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS portfolio_sales (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            position_id INTEGER NOT NULL,
            owner TEXT DEFAULT \'tiago\',
            symbol TEXT NOT NULL,
            sale_date TEXT NOT NULL,
            quantity REAL NOT NULL,
            sale_price REAL NOT NULL,
            notes TEXT DEFAULT \'\',
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS finance_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            category TEXT NOT NULL,
            description TEXT NOT NULL,
            amount REAL NOT NULL,
            due_date TEXT NOT NULL,
            status TEXT NOT NULL,
            recurrence TEXT DEFAULT \'unico\',
            installments_total INTEGER DEFAULT 1,
            installment_number INTEGER DEFAULT 1,
            actor TEXT DEFAULT \'Tiago\',
            created_by TEXT DEFAULT \'Tiago\',
            expense_tag TEXT DEFAULT \'variavel\',
            updated_by TEXT DEFAULT \'Tiago\',
            updated_at TEXT DEFAULT \'\',
            notes TEXT DEFAULT \'\',
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS finance_services (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome_cliente TEXT NOT NULL,
            valor REAL NOT NULL,
            dia_vencimento INTEGER NOT NULL,
            status TEXT DEFAULT \'pendente\',
            usuario_id TEXT DEFAULT \'tiago\',
            created_by TEXT DEFAULT \'Tiago\',
            last_paid_month TEXT DEFAULT \'\',
            updated_at TEXT DEFAULT \'\',
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS finance_cash_reset_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            status TEXT DEFAULT \'pending\',
            requested_by TEXT NOT NULL,
            counterpart_user_id TEXT NOT NULL,
            requester_confirmed INTEGER DEFAULT 0,
            counterpart_confirmed INTEGER DEFAULT 0,
            notes TEXT DEFAULT \'\',
            requested_at TEXT NOT NULL,
            executed_by TEXT DEFAULT \'\',
            executed_at TEXT DEFAULT \'\',
            cancelled_by TEXT DEFAULT \'\',
            cancelled_at TEXT DEFAULT \'\'
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS finance_cash_reset_state (
            id INTEGER PRIMARY KEY,
            baseline_income REAL DEFAULT 0,
            baseline_expense REAL DEFAULT 0,
            reset_at TEXT DEFAULT \'\',
            updated_by TEXT DEFAULT \'\',
            last_request_id INTEGER DEFAULT 0
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS family_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT DEFAULT \'tiago\',
            member TEXT NOT NULL,
            title TEXT NOT NULL,
            category TEXT NOT NULL,
            due_date TEXT NOT NULL,
            due_time TEXT DEFAULT \'\',
            hora_inicio TEXT DEFAULT \'\',
            hora_fim TEXT DEFAULT \'\',
            status TEXT NOT NULL,
            kanban_status TEXT DEFAULT \'a_fazer\',
            urgente INTEGER DEFAULT 0,
            importante INTEGER DEFAULT 0,
            archived INTEGER DEFAULT 0,
            recurrence TEXT DEFAULT \'unico\',
            notes TEXT DEFAULT \'\',
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tit_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id TEXT NOT NULL,
            sender_name TEXT NOT NULL,
            message TEXT NOT NULL,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            kind TEXT DEFAULT \'info\',
            related_type TEXT DEFAULT \'\',
            related_id INTEGER DEFAULT 0,
            is_read INTEGER DEFAULT 0,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_users (
            id TEXT PRIMARY KEY,
            username TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            short_name TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT DEFAULT \'user\',
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notification_settings (
            user_id TEXT PRIMARY KEY,
            enable_messages INTEGER DEFAULT 1,
            enable_finance_due_soon INTEGER DEFAULT 1,
            enable_finance_overdue INTEGER DEFAULT 1,
            enable_family_today INTEGER DEFAULT 1,
            enable_top_banner INTEGER DEFAULT 1,
            enable_sound INTEGER DEFAULT 0,
            quiet_hours_enabled INTEGER DEFAULT 0,
            quiet_start TEXT DEFAULT \'22:00\',
            quiet_end TEXT DEFAULT \'07:00\',
            due_soon_days INTEGER DEFAULT 3,
            updated_at TEXT NOT NULL
        )'
    );

    ensureColumn($pdo, 'finance_entries', 'recurrence', "TEXT DEFAULT 'unico'");
    ensureColumn($pdo, 'finance_entries', 'installments_total', "INTEGER DEFAULT 1");
    ensureColumn($pdo, 'finance_entries', 'installment_number', "INTEGER DEFAULT 1");
    ensureColumn($pdo, 'finance_entries', 'actor', "TEXT DEFAULT 'Tiago'");
    ensureColumn($pdo, 'finance_entries', 'created_by', "TEXT DEFAULT 'Tiago'");
    ensureColumn($pdo, 'finance_entries', 'expense_tag', "TEXT DEFAULT 'variavel'");
    ensureColumn($pdo, 'finance_entries', 'updated_by', "TEXT DEFAULT 'Tiago'");
    ensureColumn($pdo, 'finance_entries', 'updated_at', "TEXT DEFAULT ''");
    ensureColumn($pdo, 'finance_services', 'status', "TEXT DEFAULT 'pendente'");
    ensureColumn($pdo, 'finance_services', 'usuario_id', "TEXT DEFAULT 'tiago'");
    ensureColumn($pdo, 'finance_services', 'created_by', "TEXT DEFAULT 'Tiago'");
    ensureColumn($pdo, 'finance_services', 'last_paid_month', "TEXT DEFAULT ''");
    ensureColumn($pdo, 'finance_services', 'updated_at', "TEXT DEFAULT ''");
    ensureColumn($pdo, 'portfolio_positions', 'manual_current_value', 'REAL DEFAULT NULL');
    ensureColumn($pdo, 'portfolio_positions', 'asset_type', "TEXT DEFAULT 'acao'");
    ensureColumn($pdo, 'portfolio_positions', 'owner', "TEXT DEFAULT 'tiago'");
    ensureColumn($pdo, 'portfolio_positions', 'archived', 'INTEGER DEFAULT 0');
    ensureColumn($pdo, 'family_tasks', 'user_id', "TEXT DEFAULT 'tiago'");
    ensureColumn($pdo, 'family_tasks', 'hora_inicio', "TEXT DEFAULT ''");
    ensureColumn($pdo, 'family_tasks', 'hora_fim', "TEXT DEFAULT ''");
    ensureColumn($pdo, 'family_tasks', 'kanban_status', "TEXT DEFAULT 'a_fazer'");
    ensureColumn($pdo, 'family_tasks', 'urgente', 'INTEGER DEFAULT 0');
    ensureColumn($pdo, 'family_tasks', 'importante', 'INTEGER DEFAULT 0');
    ensureColumn($pdo, 'family_tasks', 'archived', 'INTEGER DEFAULT 0');
    ensureColumn($pdo, 'family_tasks', 'reminder_enabled', 'INTEGER DEFAULT 1');
    ensureColumn($pdo, 'family_tasks', 'reminder_offset_minutes', 'INTEGER DEFAULT 30');
    ensureColumn($pdo, 'notifications', 'priority', "TEXT DEFAULT 'normal'");
    ensureColumn($pdo, 'notification_settings', 'quiet_hours_enabled', 'INTEGER DEFAULT 0');
    ensureColumn($pdo, 'notification_settings', 'quiet_start', "TEXT DEFAULT '22:00'");
    ensureColumn($pdo, 'notification_settings', 'quiet_end', "TEXT DEFAULT '07:00'");
    ensureColumn($pdo, 'app_users', 'role', "TEXT DEFAULT 'user'");
    $pdo->exec("UPDATE family_tasks SET user_id = 'thais' WHERE (user_id IS NULL OR user_id = '' OR user_id = 'tiago') AND member = 'Thais'");
    $pdo->exec("UPDATE family_tasks SET user_id = 'tiago' WHERE (user_id IS NULL OR user_id = '') AND member <> 'Thais'");
    $pdo->exec("UPDATE app_users SET role = 'admin' WHERE id IN ('tiago', 'thais')");
    seedDefaultUsers($pdo);

    return $pdo;
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $columns = $pdo->query("PRAGMA table_info($table)")->fetchAll();
    foreach ($columns as $info) {
        if (($info['name'] ?? '') === $column) {
            return;
        }
    }
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
}

function seedDefaultUsers(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM app_users')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO app_users (id, username, name, short_name, password_hash, role, created_at)
         VALUES (:id, :username, :name, :short_name, :password_hash, :role, :created_at)'
    );

    foreach ([
        ['id' => 'tiago', 'username' => 'tiago', 'name' => 'Tiago Simao', 'short_name' => 'Tiago', 'password' => (string) (getenv('APP_DEFAULT_TIAGO_PASSWORD') ?: 'tiago123'), 'role' => 'admin'],
        ['id' => 'thais', 'username' => 'thais', 'name' => 'Thais Monteiro', 'short_name' => 'Thais', 'password' => (string) (getenv('APP_DEFAULT_THAIS_PASSWORD') ?: 'thais123'), 'role' => 'admin']
    ] as $user) {
        $stmt->execute([
            ':id' => $user['id'],
            ':username' => $user['username'],
            ':name' => $user['name'],
            ':short_name' => $user['short_name'],
            ':password_hash' => password_hash((string) $user['password'], PASSWORD_DEFAULT),
            ':role' => $user['role'],
            ':created_at' => gmdate('c'),
        ]);
    }
}

function loadPortfolioPositions(): array
{
    $currentUser = getCurrentUser();
    $owner = $currentUser['id'] ?? 'tiago';
    return loadPortfolioPositionsByOwner($owner, false);
}

function loadArchivedPortfolioPositions(): array
{
    $currentUser = getCurrentUser();
    $owner = $currentUser['id'] ?? 'tiago';
    return loadPortfolioPositionsByOwner($owner, true);
}

function loadPortfolioPositionsByOwner(string $owner, bool $archived = false): array
{
    $stmt = getDb()->prepare(
        'SELECT * FROM portfolio_positions
         WHERE owner = :owner AND archived = :archived
         ORDER BY id DESC'
    );
    $stmt->execute([
        ':owner' => $owner,
        ':archived' => $archived ? 1 : 0,
    ]);
    $rows = $stmt->fetchAll();
    $positions = [];
    foreach ($rows as $row) {
        try {
            $positions[] = buildPortfolioPosition(
                (int) $row['id'],
                (string) $row['purchase_date'],
                (float) $row['quantity'],
                (string) $row['symbol'],
                (float) $row['purchase_price'],
                (string) ($row['asset_type'] ?? 'acao'),
                isset($row['manual_current_value']) ? (float) $row['manual_current_value'] : 0.0
            );
        } catch (Throwable $ignored) {
        }
    }
    return $positions;
}

function loadJointPortfolioPositions(): array
{
    $users = [
        'tiago' => 'Tiago',
        'thais' => 'Thais',
    ];
    $positions = [];
    foreach ($users as $owner => $label) {
        foreach (loadPortfolioPositionsByOwner($owner) as $position) {
            $position['owner'] = $owner;
            $position['owner_label'] = $label;
            $positions[] = $position;
        }
    }
    usort($positions, static fn(array $a, array $b): int => strcmp((string) ($b['purchase_date'] ?? ''), (string) ($a['purchase_date'] ?? '')));
    return $positions;
}

function loadJointPortfolioPayload(): array
{
    $positions = loadJointPortfolioPositions();
    $invested = 0.0;
    $current = 0.0;
    $result = 0.0;

    foreach ($positions as $position) {
        $invested += (float) ($position['invested_total'] ?? 0);
        $current += (float) ($position['current_total'] ?? 0);
        $result += (float) ($position['result_value'] ?? 0);
    }

    return [
        'positions' => $positions,
        'summary' => [
            'current_total' => round($current, 2),
            'invested_total' => round($invested, 2),
            'result_total' => round($result, 2),
            'positions_count' => count($positions),
        ],
    ];
}

function buildPortfolioPosition(int $id, string $purchaseDate, float $quantity, string $symbol, float $purchasePrice, string $assetType = 'acao', float $manualCurrentValue = 0.0): array
{
    $asset = fetchAssetForPosition($symbol, $assetType, $manualCurrentValue);
    $currentValue = (float) ($asset['current_price'] ?? 0.0);
    $investedTotal = $purchasePrice * $quantity;
    $currentTotal = $currentValue * $quantity;
    $resultValue = $currentTotal - $investedTotal;
    $gainLossPct = $investedTotal > 0 ? $resultValue / $investedTotal : 0.0;
    return [
        'id' => $id,
        'purchase_date' => $purchaseDate,
        'quantity' => $quantity,
        'asset_type' => normalizePortfolioAssetType($assetType),
        'asset_type_label' => portfolioAssetTypeLabel($assetType),
        'symbol' => $asset['symbol'],
        'name' => $asset['index_name'],
        'purchase_price' => $purchasePrice,
        'manual_current_value' => $manualCurrentValue > 0 ? $manualCurrentValue : null,
        'current_value' => $currentValue,
        'invested_total' => $investedTotal,
        'current_total' => $currentTotal,
        'result_value' => $resultValue,
        'gain_loss_pct' => $gainLossPct,
        'day_change' => $asset['day_change'] ?? 0.0,
        'day_change_pct' => $asset['day_change_pct'] ?? 0.0,
        'updated_at' => $asset['updated_at'],
        'chart' => $asset['chart'],
        'gain_probability' => $asset['probabilities']['gain'],
        'loss_probability' => $asset['probabilities']['loss'],
        'stats' => $asset['stats'],
    ];
}

function getFinancePayload(): array
{
    resetMonthlyServiceStatuses();
    $rows = getDb()->query('SELECT * FROM finance_entries ORDER BY due_date ASC, id DESC')->fetchAll();
    $visibleRows = array_values(array_filter($rows, static fn(array $row): bool => !in_array((string) ($row['category'] ?? ''), ['dinheiro_guardado', 'reserva_guardada'], true)));
    $services = getFinanceServices();
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');

    $monthIncome = 0.0;
    $monthExpense = 0.0;
    $monthlySpending = 0.0;
    $monthlyToReceive = 0.0;
    $monthlyToPay = 0.0;
    $futureIncome = 0.0;
    $futureExpense = 0.0;
    $monthlyDebts = 0.0;
    $sporadicDebts = 0.0;
    $overdueExpense = 0.0;
    $overdueIncome = 0.0;
    $pendingExpense = 0.0;
    $pendingIncome = 0.0;
    $totalIncome = 0.0;
    $totalExpense = 0.0;
    $fixedExpense = 0.0;

    foreach ($visibleRows as $row) {
        $amount = (float) $row['amount'];
        $dueDate = (string) $row['due_date'];
        $category = (string) $row['category'];
        $type = normalizeFinanceType((string) $row['type'], $category);
        $status = (string) $row['status'];
        $expenseTag = normalizeExpenseTag((string) ($row['expense_tag'] ?? 'variavel'));

        if ($dueDate <= $today) {
            if ($type === 'entrada' && $status === 'recebido') {
                $totalIncome += $amount;
            } elseif ($type === 'saida' && $status === 'quitado') {
                $totalExpense += $amount;
            }
        }

        if ($dueDate >= $monthStart && $dueDate <= $monthEnd) {
            if ($type === 'entrada') {
                $monthIncome += $amount;
                if ($dueDate > $today && $status !== 'recebido' && $status !== 'quitado') {
                    $monthlyToReceive += $amount;
                }
            } else {
                $monthExpense += $amount;
                $monthlySpending += $amount;
                if ($expenseTag === 'fixo') {
                    $fixedExpense += $amount;
                }
                if ($dueDate > $today && $status !== 'recebido' && $status !== 'quitado') {
                    $monthlyToPay += $amount;
                }
            }
        }

        if ($dueDate > $today && $status !== 'quitado' && $status !== 'recebido') {
            if ($type === 'entrada') {
                $futureIncome += $amount;
            } else {
                $futureExpense += $amount;
            }
        }

        if ($dueDate < $today && $status !== 'quitado' && $status !== 'recebido') {
            if ($type === 'entrada') {
                $overdueIncome += $amount;
            } else {
                $overdueExpense += $amount;
            }
        }

        if ($status === 'programado') {
            if ($type === 'entrada') {
                $pendingIncome += $amount;
            } else {
                $pendingExpense += $amount;
            }
        }

        if ($category === 'divida_mensal') {
            $monthlyDebts += $amount;
        }
        if ($category === 'divida_esporadica') {
            $sporadicDebts += $amount;
        }
    }

    $pendingServices = array_values(array_filter($services, static fn(array $service): bool => ($service['status'] ?? 'pendente') !== 'pago'));
    $paidServices = array_values(array_filter($services, static fn(array $service): bool => ($service['status'] ?? 'pendente') === 'pago'));
    $servicesPendingTotal = array_reduce($pendingServices, static fn(float $sum, array $service): float => $sum + (float) ($service['valor'] ?? 0), 0.0);
    $servicesPaidTotal = array_reduce($paidServices, static fn(float $sum, array $service): float => $sum + (float) ($service['valor'] ?? 0), 0.0);
    $resetState = getFinanceCashResetState();
    $adjustedTotalBalance = ($totalIncome - $totalExpense) - ((float) ($resetState['baseline_income'] ?? 0) - (float) ($resetState['baseline_expense'] ?? 0));
    $projectedBalance = $adjustedTotalBalance + $servicesPendingTotal - $monthlyToPay;
    $serviceBase = max($servicesPaidTotal > 0 ? $servicesPaidTotal : ($servicesPendingTotal + $servicesPaidTotal), 0.01);
    $servicesCommitmentPct = min(1, $fixedExpense / $serviceBase);
    $todayDay = (int) date('d');
    $serviceOverdue = array_values(array_filter($pendingServices, static fn(array $service): bool => (int) ($service['dia_vencimento'] ?? 0) < $todayDay));
    $dueNext3Days = array_values(array_filter($visibleRows, static function (array $row) use ($today): bool {
        $type = normalizeFinanceType((string) ($row['type'] ?? ''), (string) ($row['category'] ?? ''));
        $status = (string) ($row['status'] ?? '');
        if ($type !== 'saida' || $status === 'quitado') {
            return false;
        }
        $todayTs = strtotime($today);
        $dueTs = strtotime((string) ($row['due_date'] ?? ''));
        if ($todayTs === false || $dueTs === false) {
            return false;
        }
        $diffDays = (int) floor(($dueTs - $todayTs) / 86400);
        return $diffDays >= 0 && $diffDays <= 3;
    }));

    return [
        'summary' => [
            'month_income' => $monthIncome,
            'month_expense' => $monthExpense,
            'monthly_spending' => $monthlySpending,
            'monthly_to_receive' => $monthlyToReceive,
            'monthly_to_pay' => $monthlyToPay,
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'total_balance' => $adjustedTotalBalance,
            'total_balance_raw' => $totalIncome - $totalExpense,
            'month_balance' => $monthIncome - $monthExpense,
            'future_income' => $futureIncome,
            'future_expense' => $futureExpense,
            'monthly_debts' => $monthlyDebts,
            'sporadic_debts' => $sporadicDebts,
            'overdue_expense' => $overdueExpense,
            'overdue_income' => $overdueIncome,
            'pending_expense' => $pendingExpense,
            'pending_income' => $pendingIncome,
            'projected_balance' => $projectedBalance,
            'services_pending_total' => $servicesPendingTotal,
            'services_paid_total' => $servicesPaidTotal,
            'fixed_expense_total' => $fixedExpense,
            'services_commitment_pct' => $servicesCommitmentPct,
            'current_month' => date('Y-m'),
            'cash_reset_state' => $resetState,
        ],
        'reminders' => [
            'overdue' => array_values(array_map(static fn(array $row): array => [
                'id' => (int) $row['id'],
                'description' => $row['description'],
                'amount' => (float) $row['amount'],
                'due_date' => $row['due_date'],
                'status' => $row['status'],
                'actor' => $row['actor'] ?? 'Tiago',
                'created_by' => $row['created_by'] ?? ($row['actor'] ?? 'Tiago'),
            ], array_filter($visibleRows, static fn(array $row): bool =>
                normalizeFinanceType((string) $row['type'], (string) $row['category']) === 'saida' &&
                $row['due_date'] < $today &&
                $row['status'] !== 'quitado'
            ))),
            'due_soon' => array_values(array_map(static fn(array $row): array => [
                'id' => (int) $row['id'],
                'description' => $row['description'],
                'amount' => (float) $row['amount'],
                'due_date' => $row['due_date'],
                'status' => $row['status'],
                'actor' => $row['actor'] ?? 'Tiago',
                'created_by' => $row['created_by'] ?? ($row['actor'] ?? 'Tiago'),
            ], array_filter($visibleRows, static fn(array $row): bool =>
                normalizeFinanceType((string) $row['type'], (string) $row['category']) === 'saida' &&
                $row['due_date'] >= $today &&
                $row['due_date'] <= date('Y-m-d', strtotime('+3 days')) &&
                $row['status'] !== 'quitado'
            ))),
        ],
        'entries' => array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'type' => normalizeFinanceType((string) $row['type'], (string) $row['category']),
            'category' => $row['category'],
            'description' => $row['description'],
            'amount' => (float) $row['amount'],
            'due_date' => $row['due_date'],
            'status' => $row['status'],
            'recurrence' => $row['recurrence'] ?? 'unico',
            'installments_total' => (int) ($row['installments_total'] ?? 1),
            'installment_number' => (int) ($row['installment_number'] ?? 1),
            'actor' => $row['actor'] ?? 'Tiago',
            'created_by' => $row['created_by'] ?? ($row['actor'] ?? 'Tiago'),
            'expense_tag' => normalizeExpenseTag((string) ($row['expense_tag'] ?? 'variavel')),
            'updated_by' => $row['updated_by'] ?? ($row['actor'] ?? 'Tiago'),
            'updated_at' => $row['updated_at'] ?? '',
            'notes' => $row['notes'],
        ], $visibleRows),
        'services' => $services,
        'attention' => [
            'services_overdue' => $serviceOverdue,
            'expenses_due_next_3_days' => array_map(static fn(array $row): array => [
                'id' => (int) $row['id'],
                'description' => $row['description'],
                'amount' => (float) $row['amount'],
                'due_date' => $row['due_date'],
                'status' => $row['status'],
                'actor' => $row['actor'] ?? 'Tiago',
                'created_by' => $row['created_by'] ?? ($row['actor'] ?? 'Tiago'),
            ], $dueNext3Days),
        ],
        'cash_reset_request' => getPendingFinanceCashResetRequest(),
    ];
}

function getFinanceServices(): array
{
    $rows = getDb()->query('SELECT * FROM finance_services ORDER BY dia_vencimento ASC, id DESC')->fetchAll();
    $currentMonth = date('Y-m');
    return array_map(static function (array $row) use ($currentMonth): array {
        $status = (string) ($row['status'] ?? 'pendente');
        $lastPaidMonth = (string) ($row['last_paid_month'] ?? '');
        if ($status === 'pago' && $lastPaidMonth !== $currentMonth) {
            $status = 'pendente';
        }
        return [
            'id' => (int) $row['id'],
            'nome_cliente' => $row['nome_cliente'],
            'valor' => (float) $row['valor'],
            'dia_vencimento' => (int) $row['dia_vencimento'],
            'status' => $status,
            'usuario_id' => $row['usuario_id'] ?? 'tiago',
            'created_by' => $row['created_by'] ?? 'Tiago',
            'last_paid_month' => $lastPaidMonth,
            'updated_at' => $row['updated_at'] ?? '',
        ];
    }, $rows);
}

function getFinanceCashResetState(): array
{
    $row = getDb()->query('SELECT * FROM finance_cash_reset_state WHERE id = 1 LIMIT 1')->fetch();
    if (!$row) {
        return [
            'baseline_income' => 0.0,
            'baseline_expense' => 0.0,
            'reset_at' => '',
            'updated_by' => '',
            'last_request_id' => 0,
        ];
    }

    return [
        'baseline_income' => (float) ($row['baseline_income'] ?? 0),
        'baseline_expense' => (float) ($row['baseline_expense'] ?? 0),
        'reset_at' => (string) ($row['reset_at'] ?? ''),
        'updated_by' => (string) ($row['updated_by'] ?? ''),
        'last_request_id' => (int) ($row['last_request_id'] ?? 0),
    ];
}

function getPendingFinanceCashResetRequest(): ?array
{
    $row = getDb()->query("SELECT * FROM finance_cash_reset_requests WHERE status = 'pending' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$row) {
        return null;
    }
    return normalizeFinanceCashResetRequest($row);
}

function normalizeFinanceCashResetRequest(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'status' => (string) ($row['status'] ?? 'pending'),
        'requested_by' => (string) ($row['requested_by'] ?? ''),
        'counterpart_user_id' => (string) ($row['counterpart_user_id'] ?? ''),
        'requester_confirmed' => (int) ($row['requester_confirmed'] ?? 0) === 1,
        'counterpart_confirmed' => (int) ($row['counterpart_confirmed'] ?? 0) === 1,
        'notes' => (string) ($row['notes'] ?? ''),
        'requested_at' => (string) ($row['requested_at'] ?? ''),
        'executed_by' => (string) ($row['executed_by'] ?? ''),
        'executed_at' => (string) ($row['executed_at'] ?? ''),
        'cancelled_by' => (string) ($row['cancelled_by'] ?? ''),
        'cancelled_at' => (string) ($row['cancelled_at'] ?? ''),
    ];
}

function getCounterpartUserId(string $userId): string
{
    return $userId === 'thais' ? 'tiago' : 'thais';
}

function getUserShortNameById(string $userId): string
{
    foreach (getAppUsers() as $user) {
        if (($user['id'] ?? '') === $userId) {
            return (string) ($user['short_name'] ?? $user['name'] ?? $userId);
        }
    }
    return ucfirst($userId);
}

function getFinanceSettledTotals(): array
{
    $rows = getDb()->query('SELECT * FROM finance_entries ORDER BY due_date ASC, id DESC')->fetchAll();
    $visibleRows = array_values(array_filter($rows, static fn(array $row): bool => !in_array((string) ($row['category'] ?? ''), ['dinheiro_guardado', 'reserva_guardada'], true)));
    $today = date('Y-m-d');
    $totalIncome = 0.0;
    $totalExpense = 0.0;

    foreach ($visibleRows as $row) {
        $amount = (float) ($row['amount'] ?? 0);
        $dueDate = (string) ($row['due_date'] ?? '');
        $category = (string) ($row['category'] ?? '');
        $type = normalizeFinanceType((string) ($row['type'] ?? ''), $category);
        $status = (string) ($row['status'] ?? '');

        if ($dueDate <= $today) {
            if ($type === 'entrada' && $status === 'recebido') {
                $totalIncome += $amount;
            } elseif ($type === 'saida' && $status === 'quitado') {
                $totalExpense += $amount;
            }
        }
    }

    return [
        'income' => $totalIncome,
        'expense' => $totalExpense,
        'balance' => $totalIncome - $totalExpense,
    ];
}

function requestFinanceCashReset(string $notes = ''): array
{
    $user = getCurrentUser();
    $userId = (string) ($user['id'] ?? '');
    if (!in_array($userId, ['tiago', 'thais'], true)) {
        throw new RuntimeException('Somente Tiago ou Thais podem solicitar o reset do caixa.');
    }

    $pending = getPendingFinanceCashResetRequest();
    if ($pending) {
        throw new RuntimeException('Ja existe um pedido de reset do caixa aguardando confirmacoes.');
    }

    $stmt = getDb()->prepare(
        'INSERT INTO finance_cash_reset_requests
        (status, requested_by, counterpart_user_id, requester_confirmed, counterpart_confirmed, notes, requested_at, executed_by, executed_at, cancelled_by, cancelled_at)
        VALUES (:status, :requested_by, :counterpart_user_id, 0, 0, :notes, :requested_at, \'\', \'\', \'\', \'\')'
    );
    $stmt->execute([
        ':status' => 'pending',
        ':requested_by' => $userId,
        ':counterpart_user_id' => getCounterpartUserId($userId),
        ':notes' => $notes,
        ':requested_at' => gmdate('c'),
    ]);

    $request = getPendingFinanceCashResetRequest();
    addSystemTitMessage(sprintf(
        'Pedido de reset do caixa criado por %s. Os dois usuarios precisam confirmar no Financeiro antes da aplicacao.%s',
        getUserShortNameById($userId),
        $notes !== '' ? ' Observacao: ' . $notes : ''
    ));
    return $request ?? [];
}

function confirmFinanceCashReset(int $id): array
{
    if ($id <= 0) {
        throw new RuntimeException('Pedido de reset invalido.');
    }
    $userId = (string) (getCurrentUser()['id'] ?? '');
    $row = getDb()->prepare('SELECT * FROM finance_cash_reset_requests WHERE id = :id LIMIT 1');
    $row->execute([':id' => $id]);
    $request = $row->fetch();
    if (!$request || ($request['status'] ?? '') !== 'pending') {
        throw new RuntimeException('Pedido de reset nao encontrado.');
    }

    if ($userId === (string) $request['requested_by']) {
        getDb()->prepare('UPDATE finance_cash_reset_requests SET requester_confirmed = 1 WHERE id = :id')->execute([':id' => $id]);
        addSystemTitMessage(getUserShortNameById($userId) . ' confirmou o pedido de reset do caixa.');
    } elseif ($userId === (string) $request['counterpart_user_id']) {
        getDb()->prepare('UPDATE finance_cash_reset_requests SET counterpart_confirmed = 1 WHERE id = :id')->execute([':id' => $id]);
        addSystemTitMessage(getUserShortNameById($userId) . ' confirmou como segundo usuario o pedido de reset do caixa.');
    } else {
        throw new RuntimeException('Voce nao participa desse pedido de reset.');
    }

    $updated = getPendingFinanceCashResetRequest();
    if ($updated && $updated['requester_confirmed'] && $updated['counterpart_confirmed']) {
        addSystemTitMessage('As duas confirmacoes do reset do caixa foram registradas. O reset ja pode ser aplicado.');
    }
    return $updated ?? [];
}

function executeFinanceCashReset(int $id): array
{
    if ($id <= 0) {
        throw new RuntimeException('Pedido de reset invalido.');
    }
    $stmt = getDb()->prepare('SELECT * FROM finance_cash_reset_requests WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $request = $stmt->fetch();
    if (!$request || ($request['status'] ?? '') !== 'pending') {
        throw new RuntimeException('Pedido de reset nao encontrado.');
    }
    if ((int) ($request['requester_confirmed'] ?? 0) !== 1 || (int) ($request['counterpart_confirmed'] ?? 0) !== 1) {
        throw new RuntimeException('O reset do caixa so pode ser aplicado depois das duas confirmacoes.');
    }

    $user = getCurrentUser();
    $userId = (string) ($user['id'] ?? '');
    if ($userId !== (string) ($request['requested_by'] ?? '')) {
        throw new RuntimeException('Somente quem abriu o pedido pode aplicar o reset do caixa.');
    }

    $totals = getFinanceSettledTotals();
    $now = gmdate('c');

    getDb()->prepare(
        'INSERT INTO finance_cash_reset_state (id, baseline_income, baseline_expense, reset_at, updated_by, last_request_id)
         VALUES (1, :baseline_income, :baseline_expense, :reset_at, :updated_by, :last_request_id)
         ON CONFLICT(id) DO UPDATE SET
           baseline_income = excluded.baseline_income,
           baseline_expense = excluded.baseline_expense,
           reset_at = excluded.reset_at,
           updated_by = excluded.updated_by,
           last_request_id = excluded.last_request_id'
    )->execute([
        ':baseline_income' => $totals['income'],
        ':baseline_expense' => $totals['expense'],
        ':reset_at' => $now,
        ':updated_by' => $userId,
        ':last_request_id' => $id,
    ]);

    getDb()->prepare(
        'UPDATE finance_cash_reset_requests
         SET status = :status, executed_by = :executed_by, executed_at = :executed_at
         WHERE id = :id'
    )->execute([
        ':status' => 'executed',
        ':executed_by' => $userId,
        ':executed_at' => $now,
        ':id' => $id,
    ]);

    addSystemTitMessage(sprintf(
        'Reset do caixa aplicado por %s. O saldo do caixa foi zerado sem remover contas e lancamentos.',
        getUserShortNameById($userId)
    ));

    return [
        'applied_at' => $now,
        'baseline' => $totals,
        'state' => getFinanceCashResetState(),
    ];
}

function cancelFinanceCashReset(int $id): array
{
    if ($id <= 0) {
        throw new RuntimeException('Pedido de reset invalido.');
    }
    $stmt = getDb()->prepare('SELECT * FROM finance_cash_reset_requests WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $request = $stmt->fetch();
    if (!$request || ($request['status'] ?? '') !== 'pending') {
        throw new RuntimeException('Pedido de reset nao encontrado.');
    }
    $userId = (string) (getCurrentUser()['id'] ?? '');
    if (!in_array($userId, [(string) ($request['requested_by'] ?? ''), (string) ($request['counterpart_user_id'] ?? '')], true) && !isAdminUser()) {
        throw new RuntimeException('Voce nao pode cancelar esse pedido.');
    }

    getDb()->prepare(
        'UPDATE finance_cash_reset_requests
         SET status = :status, cancelled_by = :cancelled_by, cancelled_at = :cancelled_at
         WHERE id = :id'
    )->execute([
        ':status' => 'cancelled',
        ':cancelled_by' => $userId,
        ':cancelled_at' => gmdate('c'),
        ':id' => $id,
    ]);

    addSystemTitMessage(getUserShortNameById($userId) . ' cancelou o pedido de reset do caixa.');
    return normalizeFinanceCashResetRequest(array_merge($request, [
        'status' => 'cancelled',
        'cancelled_by' => $userId,
        'cancelled_at' => gmdate('c'),
    ]));
}

function resetMonthlyServiceStatuses(): void
{
    $currentMonth = date('Y-m');
    getDb()->prepare(
        "UPDATE finance_services
         SET status = 'pendente', updated_at = :updated_at
         WHERE status = 'pago' AND COALESCE(last_paid_month, '') <> :current_month"
    )->execute([
        ':updated_at' => gmdate('c'),
        ':current_month' => $currentMonth,
    ]);
}

function getStyleProfilePath(): string
{
    $path = migrateLegacyFileIfNeeded('style_profile.json');
    ensureStorageDirectory((string) dirname($path));
    return $path;
}

function getDefaultStyleProfile(): array
{
    return [
        'assistant_name' => 'Assistente do Tiago',
        'description' => 'Perfil pessoal do Tiago',
        'opening_style' => 'Fala, tudo certo?',
        'closing_style' => 'Ja deixei anotado. Te retorno.',
        'favorite_words' => ['beleza', 'perfeito', 'ja vejo isso'],
        'avoid_words' => ['cordialmente', 'prezado', 'atenciosamente'],
        'notes' => 'Respostas curtas, diretas e objetivas.',
        'sample_messages' => '',
        'updated_at' => gmdate('c'),
    ];
}

function getStyleProfile(): array
{
    $path = getStyleProfilePath();
    if (!is_file($path)) {
        $default = getDefaultStyleProfile();
        file_put_contents($path, json_encode($default, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $default;
    }

    $raw = json_decode((string) file_get_contents($path), true);
    if (!is_array($raw)) {
        return getDefaultStyleProfile();
    }

    $default = getDefaultStyleProfile();
    return [
        'assistant_name' => (string) ($raw['assistant_name'] ?? $default['assistant_name']),
        'description' => (string) ($raw['description'] ?? $default['description']),
        'opening_style' => (string) ($raw['opening_style'] ?? $default['opening_style']),
        'closing_style' => (string) ($raw['closing_style'] ?? $default['closing_style']),
        'favorite_words' => array_values(array_filter(array_map('strval', (array) ($raw['favorite_words'] ?? $default['favorite_words'])))),
        'avoid_words' => array_values(array_filter(array_map('strval', (array) ($raw['avoid_words'] ?? $default['avoid_words'])))),
        'notes' => (string) ($raw['notes'] ?? $default['notes']),
        'sample_messages' => (string) ($raw['sample_messages'] ?? $default['sample_messages']),
        'updated_at' => (string) ($raw['updated_at'] ?? $default['updated_at']),
    ];
}

function saveStyleProfile(array $profile): void
{
    $path = getStyleProfilePath();
    file_put_contents($path, json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function exportFullBackup(): array
{
    $tables = [
        'portfolio_positions',
        'portfolio_sales',
        'finance_entries',
        'finance_services',
        'family_tasks',
        'finance_cash_reset_requests',
        'finance_cash_reset_state',
        'tit_messages',
        'notifications',
        'notification_settings',
        'app_users',
    ];

    $database = [];
    foreach ($tables as $table) {
        $database[$table] = getDb()->query("SELECT * FROM $table ORDER BY rowid ASC")->fetchAll();
    }

    return [
        'version' => 2,
        'exported_at' => gmdate('c'),
        'exported_by' => getCurrentUser(),
        'database' => $database,
        'files' => [
            'style_profile' => getStyleProfile(),
            'whatsapp_attendances' => readJsonFileSafe(migrateLegacyFileIfNeeded('atendimentos.json'), []),
        ],
    ];
}

function importFullBackup(array $payload): array
{
    $database = $payload['database'] ?? null;
    if (!is_array($database)) {
        throw new RuntimeException('Backup inválido: bloco de banco ausente.');
    }

    $tableColumns = [
        'portfolio_positions' => ['id', 'purchase_date', 'quantity', 'symbol', 'purchase_price', 'manual_current_value', 'asset_type', 'owner', 'archived', 'created_at'],
        'portfolio_sales' => ['id', 'position_id', 'owner', 'symbol', 'sale_date', 'quantity', 'sale_price', 'notes', 'created_at'],
        'finance_entries' => ['id', 'type', 'category', 'description', 'amount', 'due_date', 'status', 'recurrence', 'installments_total', 'installment_number', 'actor', 'created_by', 'expense_tag', 'updated_by', 'updated_at', 'notes', 'created_at'],
        'finance_services' => ['id', 'nome_cliente', 'valor', 'dia_vencimento', 'status', 'usuario_id', 'created_by', 'last_paid_month', 'updated_at', 'created_at'],
        'family_tasks' => ['id', 'user_id', 'member', 'title', 'category', 'due_date', 'due_time', 'hora_inicio', 'hora_fim', 'status', 'kanban_status', 'urgente', 'importante', 'archived', 'recurrence', 'notes', 'reminder_enabled', 'reminder_offset_minutes', 'created_at'],
        'finance_cash_reset_requests' => ['id', 'status', 'requested_by', 'counterpart_user_id', 'requester_confirmed', 'counterpart_confirmed', 'notes', 'requested_at', 'executed_by', 'executed_at', 'cancelled_by', 'cancelled_at'],
        'finance_cash_reset_state' => ['id', 'baseline_income', 'baseline_expense', 'reset_at', 'updated_by', 'last_request_id'],
        'tit_messages' => ['id', 'sender_id', 'sender_name', 'message', 'created_at'],
        'notifications' => ['id', 'user_id', 'title', 'body', 'kind', 'related_type', 'related_id', 'is_read', 'created_at', 'priority'],
        'notification_settings' => ['user_id', 'enable_messages', 'enable_finance_due_soon', 'enable_finance_overdue', 'enable_family_today', 'enable_top_banner', 'enable_sound', 'quiet_hours_enabled', 'quiet_start', 'quiet_end', 'due_soon_days', 'updated_at'],
        'app_users' => ['id', 'username', 'name', 'short_name', 'password_hash', 'role', 'created_at'],
    ];

    foreach ($tableColumns as $table => $_columns) {
        if (!array_key_exists($table, $database) || !is_array($database[$table])) {
            throw new RuntimeException("Backup inválido: tabela {$table} ausente.");
        }
    }

    $pdo = getDb();
    $pdo->beginTransaction();
    try {
        foreach (['portfolio_sales', 'portfolio_positions', 'finance_entries', 'finance_services', 'family_tasks', 'finance_cash_reset_requests', 'finance_cash_reset_state', 'tit_messages', 'notifications', 'notification_settings', 'app_users'] as $table) {
            $pdo->exec("DELETE FROM $table");
        }

        foreach ($tableColumns as $table => $columns) {
            importBackupRows($pdo, $table, $columns, $database[$table]);
            resetSqliteSequence($pdo, $table, $columns, $database[$table]);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    $files = is_array($payload['files'] ?? null) ? $payload['files'] : [];
    if (array_key_exists('style_profile', $files) && is_array($files['style_profile'])) {
        saveStyleProfile($files['style_profile']);
    }
    if (array_key_exists('whatsapp_attendances', $files) && is_array($files['whatsapp_attendances'])) {
        writeJsonFile(getStoragePath('atendimentos.json'), $files['whatsapp_attendances']);
    }

    return [
        'restored_at' => gmdate('c'),
        'rows' => array_map(static fn(array $rows): int => count($rows), $database),
    ];
}

function importBackupRows(PDO $pdo, string $table, array $columns, array $rows): void
{
    if (!$rows) {
        return;
    }

    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(', ', $columns),
        implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns))
    );
    $stmt = $pdo->prepare($sql);

    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new RuntimeException("Backup inválido: linha inválida em {$table}.");
        }
        $params = [];
        foreach ($columns as $column) {
            $params[':' . $column] = $row[$column] ?? null;
        }
        $stmt->execute($params);
    }
}

function resetSqliteSequence(PDO $pdo, string $table, array $columns, array $rows): void
{
    if (!in_array('id', $columns, true)) {
        return;
    }

    $pdo->prepare('DELETE FROM sqlite_sequence WHERE name = :name')->execute([':name' => $table]);
    if (!$rows) {
        return;
    }

    $maxId = max(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows));
    if ($maxId <= 0) {
        return;
    }

    $pdo->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (:name, :seq)')->execute([
        ':name' => $table,
        ':seq' => $maxId,
    ]);
}

function readJsonFileSafe(string $path, mixed $fallback): mixed
{
    if (!is_file($path)) {
        return $fallback;
    }
    try {
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return $fallback;
        }
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        return $fallback;
    }
}

function writeJsonFile(string $path, mixed $payload): void
{
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        throw new RuntimeException('Não foi possível gerar o arquivo JSON.');
    }
    ensureStorageDirectory((string) dirname($path));
    file_put_contents($path, $encoded);
}

function normalizeFinanceType(string $type, string $category): string
{
    if (in_array($category, ['salario', 'recebimento', 'boleto_recebido', 'negociacao', 'recebimento_futuro'], true)) {
        return 'entrada';
    }

    if (in_array($category, ['pagamento', 'pagamento_futuro', 'divida_mensal', 'divida_esporadica'], true)) {
        return 'saida';
    }

    return $type === 'entrada' ? 'entrada' : 'saida';
}

function normalizeExpenseTag(string $tag): string
{
    return strtolower(trim($tag)) === 'fixo' ? 'fixo' : 'variavel';
}

function normalizePortfolioAssetType(string $type): string
{
    $normalized = strtolower(trim($type));
    return match ($normalized) {
        'fii', 'fundo_imobiliario', 'fundo imobiliario', 'fundo imobiliário' => 'fii',
        'fundo', 'fundo_investimento', 'fundo investimento', 'fundo de investimento', 'etf' => 'fundo',
        default => 'acao',
    };
}

function portfolioAssetTypeLabel(string $type): string
{
    return match (normalizePortfolioAssetType($type)) {
        'fii' => 'Fundo imobiliário',
        'fundo' => 'Fundo de investimento',
        default => 'Ação',
    };
}

function normalizeKanbanStatus(string $status): string
{
    $normalized = strtolower(trim($status));
    return match ($normalized) {
        'fazendo' => 'fazendo',
        'feito', 'concluido', 'concluído' => 'feito',
        default => 'a_fazer',
    };
}

function getFamilyTaskById(int $id): ?array
{
    $stmt = getDb()->prepare('SELECT * FROM family_tasks WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $task = $stmt->fetch();
    return is_array($task) ? $task : null;
}

function shouldArchiveQuickTask(array $task): bool
{
    $start = trim((string) ($task['hora_inicio'] ?? $task['due_time'] ?? ''));
    $end = trim((string) ($task['hora_fim'] ?? ''));
    if ($start === '' || $end === '') {
        return false;
    }
    $duration = timeToMinutes($end) - timeToMinutes($start);
    return $duration > 0 && $duration <= 2;
}

function timeToMinutes(string $time): int
{
    if (!preg_match('/^(\d{2}):(\d{2})$/', trim($time), $matches)) {
        return 0;
    }
    return ((int) $matches[1] * 60) + (int) $matches[2];
}

function minutesToTime(int $minutes): string
{
    $minutes = max(0, min($minutes, (23 * 60) + 59));
    $hour = floor($minutes / 60);
    $minute = $minutes % 60;
    return sprintf('%02d:%02d', $hour, $minute);
}

function assertAgendaAvailability(string $userId, string $date, string $startTime, string $endTime, int $ignoreId = 0): void
{
    if ($startTime === '' || $endTime === '') {
        return;
    }

    $start = timeToMinutes($startTime);
    $end = timeToMinutes($endTime);
    if ($end <= $start) {
        throw new RuntimeException('Hora final deve ser maior que a hora inicial.');
    }

    $stmt = getDb()->prepare(
        'SELECT * FROM family_tasks
         WHERE user_id = :user_id AND due_date = :due_date AND archived = 0 AND id <> :id'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':due_date' => $date,
        ':id' => $ignoreId,
    ]);
    $rows = $stmt->fetchAll();
    $nextFree = $start;

    foreach ($rows as $row) {
        $otherStart = timeToMinutes((string) ($row['hora_inicio'] ?? $row['due_time'] ?? ''));
        $otherEnd = timeToMinutes((string) ($row['hora_fim'] ?? ''));
        if ($otherStart <= 0 || $otherEnd <= 0) {
            continue;
        }
        if ($start < $otherEnd && $end > $otherStart) {
            $nextFree = max($nextFree, $otherEnd);
        }
    }

    if ($nextFree !== $start) {
        $duration = $end - $start;
        $suggestedStart = minutesToTime($nextFree);
        $suggestedEnd = minutesToTime($nextFree + $duration);
        throw new RuntimeException("Conflito de Agenda. Próximo horário vago sugerido: {$suggestedStart} até {$suggestedEnd}.");
    }
}

function getFamilyPayload(): array
{
    $currentUser = getCurrentUser();
    $personalMember = $currentUser['short_name'] ?? 'Tiago';
    $members = ['Tiago', 'Thais', 'Ãcaro'];
    $rows = getDb()->query('SELECT * FROM family_tasks ORDER BY due_date ASC, due_time ASC, id DESC')->fetchAll();
    $summary = [];

    foreach ($members as $member) {
        $memberRows = array_values(array_filter($rows, static fn(array $row): bool => $row['member'] === $member));
        $summary[$member] = [
            'pending' => count(array_filter($memberRows, static fn(array $row): bool => $row['status'] !== 'concluido')),
            'done' => count(array_filter($memberRows, static fn(array $row): bool => $row['status'] === 'concluido')),
            'water' => count(array_filter($memberRows, static fn(array $row): bool => $row['category'] === 'agua')),
        ];
    }

    return [
        'members' => $members,
        'personal_member' => $personalMember,
        'summary' => $summary,
        'dashboards' => [
            'tiago' => array_values(array_filter($rows, static fn(array $row): bool => $row['member'] === 'Tiago')),
            'thais' => array_values(array_filter($rows, static fn(array $row): bool => $row['member'] === 'Thais')),
            'icaro' => array_values(array_filter($rows, static fn(array $row): bool => $row['member'] === 'Ãcaro')),
        ],
        'tasks' => array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'member' => $row['member'],
            'title' => $row['title'],
            'category' => $row['category'],
            'due_date' => $row['due_date'],
            'due_time' => $row['due_time'],
            'status' => $row['status'],
            'recurrence' => $row['recurrence'],
            'notes' => $row['notes'],
        ], $rows),
    ];
}

function addTitMessage(string $message): void
{
    $user = getCurrentUser();
    $senderId = (string) ($user['id'] ?? 'tiago');
    $senderName = (string) ($user['short_name'] ?? 'Tiago');
    $createdAt = gmdate('c');

    $stmt = getDb()->prepare(
        'INSERT INTO tit_messages (sender_id, sender_name, message, created_at)
         VALUES (:sender_id, :sender_name, :message, :created_at)'
    );
    $stmt->execute([
        ':sender_id' => $senderId,
        ':sender_name' => $senderName,
        ':message' => $message,
        ':created_at' => $createdAt,
    ]);

    foreach (getAppUsers() as $appUser) {
        if (($appUser['id'] ?? '') === $senderId) {
            continue;
        }
        addNotification(
            (string) $appUser['id'],
            'Nova mensagem em T i T',
            $senderName . ': ' . mb_substr($message, 0, 120),
            'message',
            'tit_message',
            (int) getDb()->lastInsertId(),
            'high'
        );
    }
}

function addSystemTitMessage(string $message): void
{
    $createdAt = gmdate('c');
    $stmt = getDb()->prepare(
        'INSERT INTO tit_messages (sender_id, sender_name, message, created_at)
         VALUES (:sender_id, :sender_name, :message, :created_at)'
    );
    $stmt->execute([
        ':sender_id' => 'system',
        ':sender_name' => 'Sistema',
        ':message' => $message,
        ':created_at' => $createdAt,
    ]);

    $messageId = (int) getDb()->lastInsertId();
    foreach (getAppUsers() as $appUser) {
        addNotification(
            (string) $appUser['id'],
            'Aviso financeiro',
            mb_substr($message, 0, 120),
            'message',
            'finance_cash_reset',
            $messageId,
            'high'
        );
    }
}

function getTitMessagesPayload(): array
{
    $rows = getDb()->query('SELECT * FROM tit_messages ORDER BY id DESC LIMIT 80')->fetchAll();
    $rows = array_reverse($rows);
    return [
        'messages' => array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'sender_id' => $row['sender_id'],
            'sender_name' => $row['sender_name'],
            'message' => $row['message'],
            'created_at' => $row['created_at'],
        ], $rows),
    ];
}

function addNotification(string $userId, string $title, string $body, string $kind = 'info', string $relatedType = '', int $relatedId = 0, string $priority = 'normal'): void
{
    if (!shouldStoreNotification($userId, $kind)) {
        return;
    }

    $stmt = getDb()->prepare(
        'INSERT INTO notifications (user_id, title, body, kind, related_type, related_id, priority, is_read, created_at)
         VALUES (:user_id, :title, :body, :kind, :related_type, :related_id, :priority, 0, :created_at)'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':title' => $title,
        ':body' => $body,
        ':kind' => $kind,
        ':related_type' => $relatedType,
        ':related_id' => $relatedId,
        ':priority' => $priority,
        ':created_at' => gmdate('c'),
    ]);
}

function getFamilyPayloadV2(): array
{
    $currentUser = getCurrentUser();
    $userId = (string) ($currentUser['id'] ?? 'tiago');
    $stmt = getDb()->prepare('SELECT * FROM family_tasks WHERE user_id = :user_id ORDER BY due_date ASC, hora_inicio ASC, due_time ASC, id DESC');
    $stmt->execute([':user_id' => $userId]);
    $rows = $stmt->fetchAll();

    $tasks = array_map(static function (array $row): array {
        $urgent = (int) ($row['urgente'] ?? 0);
        $important = (int) ($row['importante'] ?? 0);
        return [
            'id' => (int) $row['id'],
            'user_id' => (string) ($row['user_id'] ?? 'tiago'),
            'member' => $row['member'],
            'title' => $row['title'],
            'category' => $row['category'],
            'due_date' => $row['due_date'],
            'due_time' => $row['due_time'],
            'hora_inicio' => $row['hora_inicio'] ?: $row['due_time'],
            'hora_fim' => $row['hora_fim'],
            'status' => $row['status'],
            'kanban_status' => normalizeKanbanStatus((string) ($row['kanban_status'] ?? 'a_fazer')),
            'urgente' => $urgent,
            'importante' => $important,
            'peso' => ($urgent * 2) + ($important * 3),
            'archived' => (int) ($row['archived'] ?? 0),
            'recurrence' => $row['recurrence'],
            'notes' => $row['notes'],
            'reminder_enabled' => (int) ($row['reminder_enabled'] ?? 1) === 1,
            'reminder_offset_minutes' => (int) ($row['reminder_offset_minutes'] ?? 30),
        ];
    }, $rows);

    usort($tasks, static function (array $a, array $b): int {
        $weightCompare = ((int) ($b['peso'] ?? 0)) <=> ((int) ($a['peso'] ?? 0));
        if ($weightCompare !== 0) {
            return $weightCompare;
        }
        return strcmp((string) ($a['hora_inicio'] ?? ''), (string) ($b['hora_inicio'] ?? ''));
    });

    $activeTasks = array_values(array_filter($tasks, static fn(array $task): bool => (int) ($task['archived'] ?? 0) === 0));
    $archivedTasks = array_values(array_filter($tasks, static fn(array $task): bool => (int) ($task['archived'] ?? 0) === 1));
    $today = date('Y-m-d');

    return [
        'user_id' => $userId,
        'personal_member' => (string) ($currentUser['short_name'] ?? 'Tiago'),
        'summary' => [
            'total' => count($activeTasks),
            'todo' => count(array_filter($activeTasks, static fn(array $task): bool => ($task['kanban_status'] ?? '') === 'a_fazer')),
            'doing' => count(array_filter($activeTasks, static fn(array $task): bool => ($task['kanban_status'] ?? '') === 'fazendo')),
            'done' => count(array_filter($activeTasks, static fn(array $task): bool => ($task['kanban_status'] ?? '') === 'feito')),
            'archived' => count($archivedTasks),
            'today' => count(array_filter($activeTasks, static fn(array $task): bool => ($task['due_date'] ?? '') === $today)),
        ],
        'agenda' => array_values(array_filter($activeTasks, static fn(array $task): bool => (string) ($task['hora_inicio'] ?? '') !== '')),
        'kanban' => [
            'a_fazer' => array_values(array_filter($activeTasks, static fn(array $task): bool => ($task['kanban_status'] ?? '') === 'a_fazer')),
            'fazendo' => array_values(array_filter($activeTasks, static fn(array $task): bool => ($task['kanban_status'] ?? '') === 'fazendo')),
            'feito' => array_values(array_filter($activeTasks, static fn(array $task): bool => ($task['kanban_status'] ?? '') === 'feito')),
        ],
        'archived_tasks' => $archivedTasks,
        'tasks' => $activeTasks,
    ];
}

function markNotificationRead(int $id): void
{
    $user = getCurrentUser();
    $stmt = getDb()->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id');
    $stmt->execute([
        ':id' => $id,
        ':user_id' => (string) ($user['id'] ?? 'tiago'),
    ]);
}

function markAllNotificationsRead(): void
{
    $user = getCurrentUser();
    $stmt = getDb()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id');
    $stmt->execute([
        ':user_id' => (string) ($user['id'] ?? 'tiago'),
    ]);
}

function getDefaultNotificationSettings(): array
{
    return [
        'enable_messages' => true,
        'enable_finance_due_soon' => true,
        'enable_finance_overdue' => true,
        'enable_family_today' => true,
        'enable_top_banner' => true,
        'enable_sound' => false,
        'quiet_hours_enabled' => false,
        'quiet_start' => '22:00',
        'quiet_end' => '07:00',
        'due_soon_days' => 3,
    ];
}

function normalizeNotificationBool(mixed $value): int
{
    $normalized = is_string($value) ? mb_strtolower(trim($value)) : $value;
    return in_array($normalized, [1, '1', true, 'true', 'on', 'yes', 'sim'], true) ? 1 : 0;
}

function getNotificationSettings(?string $userId = null): array
{
    if ($userId === null || $userId === '') {
        $user = getCurrentUser();
        $userId = (string) ($user['id'] ?? 'tiago');
    }

    $stmt = getDb()->prepare('SELECT * FROM notification_settings WHERE user_id = :user_id LIMIT 1');
    $stmt->execute([':user_id' => $userId]);
    $row = $stmt->fetch();

    if (!$row) {
        $defaults = getDefaultNotificationSettings();
        $insert = getDb()->prepare(
            'INSERT INTO notification_settings (
                user_id,
                enable_messages,
                enable_finance_due_soon,
                enable_finance_overdue,
                enable_family_today,
                enable_top_banner,
                enable_sound,
                quiet_hours_enabled,
                quiet_start,
                quiet_end,
                due_soon_days,
                updated_at
            ) VALUES (
                :user_id,
                :enable_messages,
                :enable_finance_due_soon,
                :enable_finance_overdue,
                :enable_family_today,
                :enable_top_banner,
                :enable_sound,
                :quiet_hours_enabled,
                :quiet_start,
                :quiet_end,
                :due_soon_days,
                :updated_at
            )'
        );
        $insert->execute([
            ':user_id' => $userId,
            ':enable_messages' => $defaults['enable_messages'] ? 1 : 0,
            ':enable_finance_due_soon' => $defaults['enable_finance_due_soon'] ? 1 : 0,
            ':enable_finance_overdue' => $defaults['enable_finance_overdue'] ? 1 : 0,
            ':enable_family_today' => $defaults['enable_family_today'] ? 1 : 0,
            ':enable_top_banner' => $defaults['enable_top_banner'] ? 1 : 0,
            ':enable_sound' => $defaults['enable_sound'] ? 1 : 0,
            ':quiet_hours_enabled' => $defaults['quiet_hours_enabled'] ? 1 : 0,
            ':quiet_start' => $defaults['quiet_start'],
            ':quiet_end' => $defaults['quiet_end'],
            ':due_soon_days' => $defaults['due_soon_days'],
            ':updated_at' => gmdate('c'),
        ]);
        return $defaults;
    }

    return [
        'enable_messages' => (int) ($row['enable_messages'] ?? 1) === 1,
        'enable_finance_due_soon' => (int) ($row['enable_finance_due_soon'] ?? 1) === 1,
        'enable_finance_overdue' => (int) ($row['enable_finance_overdue'] ?? 1) === 1,
        'enable_family_today' => (int) ($row['enable_family_today'] ?? 1) === 1,
        'enable_top_banner' => (int) ($row['enable_top_banner'] ?? 1) === 1,
        'enable_sound' => (int) ($row['enable_sound'] ?? 0) === 1,
        'quiet_hours_enabled' => (int) ($row['quiet_hours_enabled'] ?? 0) === 1,
        'quiet_start' => (string) ($row['quiet_start'] ?? '22:00'),
        'quiet_end' => (string) ($row['quiet_end'] ?? '07:00'),
        'due_soon_days' => max(1, min(7, (int) ($row['due_soon_days'] ?? 3))),
    ];
}

function updateNotificationSettings(array $payload): array
{
    $user = getCurrentUser();
    $userId = (string) ($user['id'] ?? 'tiago');
    $current = getNotificationSettings($userId);
    $days = isset($payload['due_soon_days']) ? (int) $payload['due_soon_days'] : (int) $current['due_soon_days'];
    $days = max(1, min(7, $days));

    $settings = [
        'enable_messages' => normalizeNotificationBool($payload['enable_messages'] ?? $current['enable_messages']) === 1,
        'enable_finance_due_soon' => normalizeNotificationBool($payload['enable_finance_due_soon'] ?? $current['enable_finance_due_soon']) === 1,
        'enable_finance_overdue' => normalizeNotificationBool($payload['enable_finance_overdue'] ?? $current['enable_finance_overdue']) === 1,
        'enable_family_today' => normalizeNotificationBool($payload['enable_family_today'] ?? $current['enable_family_today']) === 1,
        'enable_top_banner' => normalizeNotificationBool($payload['enable_top_banner'] ?? $current['enable_top_banner']) === 1,
        'enable_sound' => normalizeNotificationBool($payload['enable_sound'] ?? $current['enable_sound']) === 1,
        'quiet_hours_enabled' => normalizeNotificationBool($payload['quiet_hours_enabled'] ?? $current['quiet_hours_enabled']) === 1,
        'quiet_start' => trim((string) ($payload['quiet_start'] ?? $current['quiet_start'] ?? '22:00')) ?: '22:00',
        'quiet_end' => trim((string) ($payload['quiet_end'] ?? $current['quiet_end'] ?? '07:00')) ?: '07:00',
        'due_soon_days' => $days,
    ];

    $stmt = getDb()->prepare(
        'INSERT INTO notification_settings (
            user_id,
            enable_messages,
            enable_finance_due_soon,
            enable_finance_overdue,
            enable_family_today,
            enable_top_banner,
            enable_sound,
            quiet_hours_enabled,
            quiet_start,
            quiet_end,
            due_soon_days,
            updated_at
        ) VALUES (
            :user_id,
            :enable_messages,
            :enable_finance_due_soon,
            :enable_finance_overdue,
            :enable_family_today,
            :enable_top_banner,
            :enable_sound,
            :quiet_hours_enabled,
            :quiet_start,
            :quiet_end,
            :due_soon_days,
            :updated_at
        )
        ON CONFLICT(user_id) DO UPDATE SET
            enable_messages = excluded.enable_messages,
            enable_finance_due_soon = excluded.enable_finance_due_soon,
            enable_finance_overdue = excluded.enable_finance_overdue,
            enable_family_today = excluded.enable_family_today,
            enable_top_banner = excluded.enable_top_banner,
            enable_sound = excluded.enable_sound,
            quiet_hours_enabled = excluded.quiet_hours_enabled,
            quiet_start = excluded.quiet_start,
            quiet_end = excluded.quiet_end,
            due_soon_days = excluded.due_soon_days,
            updated_at = excluded.updated_at'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':enable_messages' => $settings['enable_messages'] ? 1 : 0,
        ':enable_finance_due_soon' => $settings['enable_finance_due_soon'] ? 1 : 0,
        ':enable_finance_overdue' => $settings['enable_finance_overdue'] ? 1 : 0,
        ':enable_family_today' => $settings['enable_family_today'] ? 1 : 0,
        ':enable_top_banner' => $settings['enable_top_banner'] ? 1 : 0,
        ':enable_sound' => $settings['enable_sound'] ? 1 : 0,
        ':quiet_hours_enabled' => $settings['quiet_hours_enabled'] ? 1 : 0,
        ':quiet_start' => $settings['quiet_start'],
        ':quiet_end' => $settings['quiet_end'],
        ':due_soon_days' => $settings['due_soon_days'],
        ':updated_at' => gmdate('c'),
    ]);

    return $settings;
}

function shouldStoreNotification(string $userId, string $kind): bool
{
    $settings = getNotificationSettings($userId);
    if ($kind === 'message') {
        return (bool) ($settings['enable_messages'] ?? true);
    }
    return true;
}

function getNotificationPriority(string $kind, string $title = ''): string
{
    $titleKey = mb_strtolower($title);
    if ($kind === 'message') {
        return 'high';
    }
    if ($kind === 'finance' && str_contains($titleKey, 'atras')) {
        return 'high';
    }
    if ($kind === 'finance') {
        return 'medium';
    }
    if ($kind === 'family') {
        return 'medium';
    }
    return 'low';
}

function getNotificationsPayload(): array
{
    $user = getCurrentUser();
    $userId = (string) ($user['id'] ?? 'tiago');
    $settings = getNotificationSettings($userId);

    $stmt = getDb()->prepare('SELECT * FROM notifications WHERE user_id = :user_id ORDER BY id DESC LIMIT 50');
    $stmt->execute([':user_id' => $userId]);
    $rows = array_values(array_filter($stmt->fetchAll(), static function (array $row) use ($settings): bool {
        if (($row['kind'] ?? '') === 'message' && !($settings['enable_messages'] ?? true)) {
            return false;
        }
        return true;
    }));

    $dynamic = getDynamicNotifications($settings);
    $items = array_merge(
        array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'body' => $row['body'],
            'kind' => $row['kind'],
            'priority' => $row['priority'] ?: getNotificationPriority((string) ($row['kind'] ?? 'info'), (string) ($row['title'] ?? '')),
            'is_read' => (int) $row['is_read'] === 1,
            'created_at' => $row['created_at'],
            'source' => 'db',
        ], $rows),
        $dynamic
    );

    usort($items, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

    $unreadDbNotifications = array_filter($rows, static fn(array $row): bool => (int) ($row['is_read'] ?? 0) !== 1);
    $unreadMessages = array_filter(
        $rows,
        static fn(array $row): bool => ($row['kind'] ?? '') === 'message' && (int) ($row['is_read'] ?? 0) !== 1
    );

    return [
        'notifications' => $items,
        'unread_count' => count($unreadDbNotifications),
        'unread_db_count' => count($unreadDbNotifications),
        'unread_messages_count' => count($unreadMessages),
        'settings' => $settings,
    ];
}

function getDynamicNotifications(?array $settings = null): array
{
    $settings ??= getNotificationSettings();
    $user = getCurrentUser();
    $shortName = (string) ($user['short_name'] ?? 'Tiago');
    $notifications = [];
    $today = date('Y-m-d');

    if ($settings['enable_finance_due_soon'] ?? true) {
        $limitDate = date('Y-m-d', strtotime('+' . (int) ($settings['due_soon_days'] ?? 3) . ' days'));
        $dueSoonSource = array_values(array_filter(
            getFinancePayload()['reminders']['due_soon'] ?? [],
            static fn(array $item): bool => ($item['due_date'] ?? '') <= $limitDate
        ));
        foreach (array_slice($dueSoonSource, 0, 5) as $item) {
            $notifications[] = [
                'id' => 0,
                'title' => 'Conta próxima do vencimento',
                'body' => ($item['description'] ?? 'Título') . ' vence em ' . ($item['due_date'] ?? ''),
                'kind' => 'finance',
                'priority' => 'medium',
                'is_read' => true,
                'created_at' => gmdate('c'),
                'source' => 'dynamic',
            ];
        }
    }

    if ($settings['enable_finance_overdue'] ?? true) {
        foreach (array_slice(getFinancePayload()['reminders']['overdue'] ?? [], 0, 5) as $item) {
            $notifications[] = [
                'id' => 0,
                'title' => 'Conta atrasada',
                'body' => ($item['description'] ?? 'Título') . ' está em atraso',
                'kind' => 'finance',
                'priority' => 'high',
                'is_read' => true,
                'created_at' => gmdate('c'),
                'source' => 'dynamic',
            ];
        }
    }

    if ($settings['enable_family_today'] ?? true) {
        foreach (getFamilyPayload()['tasks'] ?? [] as $task) {
            if (($task['member'] ?? '') !== $shortName && ($task['member'] ?? '') !== 'Ícaro') {
                continue;
            }
            if (($task['due_date'] ?? '') === $today && ($task['status'] ?? '') !== 'concluido') {
                $notifications[] = [
                    'id' => 0,
                    'title' => 'Rotina para hoje',
                    'body' => ($task['title'] ?? 'Tarefa') . ' vence hoje',
                    'kind' => 'family',
                    'priority' => 'medium',
                    'is_read' => true,
                    'created_at' => gmdate('c'),
                    'source' => 'dynamic',
                ];
            }
        }
    }

    return $notifications;
}
function shiftMonths(string $date, int $months): string
{
    $dt = new DateTimeImmutable($date);
    return $dt->modify('+' . $months . ' month')->format('Y-m-d');
}

function getIbovespaDataset(): array
{
    $dataDir = getStoragePath('data');
    $cacheFile = $dataDir . DIRECTORY_SEPARATOR . 'ibovespa_cache.json';
    $today = date('Y-m-d');

    if (is_file($cacheFile)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['cache_date'] ?? '') === $today) {
            return $cached;
        }
    }

    ensureStorageDirectory($dataDir);

    $fresh = fetchAssetHistory('^BVSP');
    $payload = [
        'cache_date' => $today,
        'source' => 'Yahoo Finance chart endpoint',
        'meta' => $fresh['meta'],
        'history' => $fresh['history'],
        'fetched_at' => $fresh['fetched_at'],
    ];

    file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $payload;
}

function normalizeSymbol(string $symbol): string
{
    $cleaned = strtoupper(trim($symbol));
    $cleaned = preg_replace('/\s+/', '', $cleaned) ?? $cleaned;
    if ($cleaned === '') {
        return '';
    }

    if (in_array($cleaned, ['IBOVESPA', 'IBOV', 'BVSP', '^BVSP'], true)) {
        return '^BVSP';
    }

    if (str_contains($cleaned, '.') || str_starts_with($cleaned, '^') || str_contains($cleaned, '-')) {
        return $cleaned;
    }

    return $cleaned . '.SA';
}

function getSymbolCandidates(string $symbol): array
{
    $normalized = normalizeSymbol($symbol);
    if ($normalized === '') {
        return [];
    }

    $candidates = [$normalized];
    if (preg_match('/^([A-Z]{4}\d{1,2})F\.SA$/', $normalized, $matches)) {
        $candidates[] = $matches[1] . '.SA';
    }

    return array_values(array_unique(array_filter($candidates)));
}

function fetchAssetHistoryOnce(string $normalized): array
{
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($normalized) . '?range=18mo&interval=1d';
    $raw = fetchJson($url);

    if (!isset($raw['chart']['result'][0])) {
        throw new RuntimeException('NÃ£o foi possÃ­vel encontrar dados para ' . $normalized . '.');
    }

    $result = $raw['chart']['result'][0];
    $meta = $result['meta'] ?? [];
    $quote = $result['indicators']['quote'][0] ?? [];
    $timestamps = $result['timestamp'] ?? [];
    $closes = $quote['close'] ?? [];
    $opens = $quote['open'] ?? [];
    $highs = $quote['high'] ?? [];
    $lows = $quote['low'] ?? [];
    $volumes = $quote['volume'] ?? [];
    $history = [];

    foreach ($timestamps as $index => $timestamp) {
        $closeValue = $closes[$index] ?? null;
        if ($closeValue === null) {
            continue;
        }

        $history[] = [
            'date' => gmdate('Y-m-d', (int) $timestamp),
            'close' => round((float) $closeValue, 2),
            'open' => isset($opens[$index]) ? round((float) $opens[$index], 2) : null,
            'high' => isset($highs[$index]) ? round((float) $highs[$index], 2) : null,
            'low' => isset($lows[$index]) ? round((float) $lows[$index], 2) : null,
            'volume' => isset($volumes[$index]) ? (int) $volumes[$index] : null,
        ];
    }

    return [
        'meta' => [
            'currency' => $meta['currency'] ?? 'BRL',
            'symbol' => $meta['symbol'] ?? $normalized,
            'name' => $meta['shortName'] ?? $meta['longName'] ?? ($meta['symbol'] ?? $normalized),
            'exchange_name' => $meta['fullExchangeName'] ?? 'SÃ£o Paulo',
            'timezone' => $meta['exchangeTimezoneName'] ?? 'America/Sao_Paulo',
            'regular_market_price' => $meta['regularMarketPrice'] ?? null,
            'previous_close' => $meta['chartPreviousClose'] ?? null,
        ],
        'history' => $history,
        'fetched_at' => gmdate('c'),
    ];
}

function fetchAssetHistory(string $symbol): array
{
    $candidates = getSymbolCandidates($symbol);
    if (!$candidates) {
        throw new RuntimeException('SÃ­mbolo invÃ¡lido.');
    }

    $bestDataset = null;
    $lastError = null;
    foreach ($candidates as $candidate) {
        try {
            $dataset = fetchAssetHistoryOnce($candidate);
            if ($bestDataset === null || count($dataset['history']) > count($bestDataset['history'] ?? [])) {
                $bestDataset = $dataset;
            }
            if (count($dataset['history']) >= 2) {
                return $dataset;
            }
        } catch (Throwable $error) {
            $lastError = $error;
        }
    }

    if ($bestDataset !== null) {
        return $bestDataset;
    }

    throw $lastError instanceof Throwable ? $lastError : new RuntimeException('Falha ao consultar ativo.');
}

function fetchAssetForPosition(string $symbol, string $assetType = 'acao', float $manualCurrentValue = 0.0): array
{
    $normalizedAssetType = normalizePortfolioAssetType($assetType);
    try {
        $dataset = fetchAssetHistory($symbol);
        $dataset['source'] = 'Yahoo Finance chart endpoint';
        return calculateMetrics($dataset);
    } catch (Throwable $error) {
        if (in_array($normalizedAssetType, ['fii', 'fundo'], true) && $manualCurrentValue > 0) {
            return buildManualPortfolioAsset($symbol, $manualCurrentValue);
        }
        if ($normalizedAssetType === 'fundo') {
            throw new RuntimeException('Para fundo de investimento, informe o valor atual manualmente.');
        }
        if ($normalizedAssetType === 'fii') {
            throw new RuntimeException('Cotação online não encontrada para este fundo imobiliário. Informe o valor atual manualmente.');
        }
        $dataset = fetchAssetHistory($symbol);
        $quote = buildQuotePayload($dataset);
        $previousClose = (float) ($quote['previous_close'] ?? $quote['last_close'] ?? 0.0);
        $currentPrice = (float) ($quote['current_price'] ?? $quote['last_close'] ?? 0.0);
        $dayChange = $currentPrice - $previousClose;
        $dayChangePct = $previousClose > 0 ? $dayChange / $previousClose : 0.0;
        return [
            'updated_at' => $quote['updated_at'],
            'source' => 'Yahoo Finance chart endpoint',
            'symbol' => $quote['symbol'],
            'index_name' => $quote['name'],
            'exchange_name' => $quote['exchange_name'],
            'currency' => $quote['currency'],
            'current_price' => $currentPrice,
            'last_close' => $quote['last_close'],
            'previous_close' => $previousClose,
            'day_change' => $dayChange,
            'day_change_pct' => $dayChangePct,
            'probabilities' => [
                'gain' => 0.5,
                'loss' => 0.5,
                'empirical_gain' => 0.5,
                'empirical_loss' => 0.5,
            ],
            'stats' => [
                'lookback_days' => 1,
                'average_daily_return' => 0.0,
                'recent_bias' => 0.0,
                'daily_volatility' => 0.0,
                'today_volume' => (int) (($dataset['history'][count($dataset['history']) - 1]['volume'] ?? 0)),
                'momentum_20d' => 0.0,
                'range_position_60d' => 0.5,
                'positive_days' => 0,
                'negative_days' => 0,
                'flat_days' => 1,
                'high_60d' => $quote['last_close'],
                'low_60d' => $quote['last_close'],
            ],
            'chart' => [],
            'disclaimer' => 'Ativo com histÃ³rico curto. Probabilidades neutras temporÃ¡rias.',
        ];
    }
}

function buildManualPortfolioAsset(string $symbol, float $manualCurrentValue): array
{
    $normalizedSymbol = strtoupper(trim($symbol));
    return [
        'updated_at' => gmdate('c'),
        'source' => 'Valor manual',
        'symbol' => $normalizedSymbol,
        'index_name' => $normalizedSymbol,
        'exchange_name' => 'Manual',
        'currency' => 'BRL',
        'current_price' => $manualCurrentValue,
        'last_close' => $manualCurrentValue,
        'previous_close' => $manualCurrentValue,
        'day_change' => 0.0,
        'day_change_pct' => 0.0,
        'probabilities' => [
            'gain' => 0.5,
            'loss' => 0.5,
            'empirical_gain' => 0.5,
            'empirical_loss' => 0.5,
        ],
        'stats' => [
            'lookback_days' => 1,
            'average_daily_return' => 0.0,
            'recent_bias' => 0.0,
            'daily_volatility' => 0.0,
            'today_volume' => 0,
            'momentum_20d' => 0.0,
            'range_position_60d' => 0.5,
            'positive_days' => 0,
            'negative_days' => 0,
            'flat_days' => 1,
            'high_60d' => $manualCurrentValue,
            'low_60d' => $manualCurrentValue,
        ],
        'chart' => [],
        'disclaimer' => 'Fundo monitorado com valor manual. Atualize o valor atual quando necessário.',
    ];
}

function fetchJson(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0\r\nAccept: application/json\r\n",
            'timeout' => 20,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('Falha ao consultar a fonte de dados do mercado.');
    }

    $statusCode = 0;
    foreach ($http_response_header ?? [] as $headerLine) {
        if (preg_match('/HTTP\/\S+\s+(\d{3})/', $headerLine, $matches)) {
            $statusCode = (int) $matches[1];
            break;
        }
    }

    if ($statusCode >= 400) {
        throw new RuntimeException('Falha ao consultar a fonte de dados do mercado. HTTP ' . $statusCode . '.');
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Resposta invÃ¡lida da fonte de dados.');
    }

    return $decoded;
}

function calculateMetrics(array $dataset): array
{
    $history = $dataset['history'] ?? [];
    if (count($history) < 2) {
        throw new RuntimeException('HistÃ³rico insuficiente para exibir o ativo.');
    }

    $closes = array_map(static fn(array $item): float => (float) $item['close'], $history);
    $returns = [];
    for ($i = 1, $total = count($closes); $i < $total; $i++) {
        $returns[] = ($closes[$i] / $closes[$i - 1]) - 1;
    }

    $lookback = min(252, max(1, count($returns)));
    $trailing = array_slice($returns, -$lookback);
    $recent = array_slice($returns, -min(20, count($returns)));
    if (!$recent) {
        $recent = $trailing;
    }

    $avgReturn = array_sum($trailing) / count($trailing);
    $varianceBase = 0.0;
    foreach ($trailing as $value) {
        $varianceBase += ($value - $avgReturn) ** 2;
    }
    $volatility = sqrt($varianceBase / count($trailing));

    $positiveDays = count(array_filter($trailing, static fn(float $value): bool => $value > 0));
    $negativeDays = count(array_filter($trailing, static fn(float $value): bool => $value < 0));
    $flatDays = count($trailing) - $positiveDays - $negativeDays;
    $empiricalGain = $positiveDays / count($trailing);
    $empiricalLoss = $negativeDays / count($trailing);

    $recentBias = array_sum($recent) / count($recent);
    $adjustedMean = ($avgReturn * 0.6) + ($recentBias * 0.4);
    $modelGain = $volatility > 0 ? 1 - normalCdf(0.0, $adjustedMean, $volatility) : ($adjustedMean > 0 ? 1.0 : 0.0);
    $modelLoss = 1 - $modelGain;

    $lastClose = $closes[count($closes) - 1];
    $previousClose = $closes[count($closes) - 2];
    $dayChange = $lastClose - $previousClose;
    $dayChangePct = $dayChange / $previousClose;
    $chartSlice = array_slice($history, -min(60, count($history)));
    $base20 = count($closes) > 20 ? $closes[count($closes) - 21] : $closes[0];
    $momentum20d = $base20 > 0 ? ($lastClose / $base20) - 1 : 0.0;
    $rangeSpread = max(array_column($chartSlice, 'close')) - min(array_column($chartSlice, 'close'));
    $rangePosition60d = $rangeSpread > 0 ? ($lastClose - min(array_column($chartSlice, 'close'))) / $rangeSpread : 0.5;
    $chart = array_map(static fn(array $item): array => [
        'date' => $item['date'],
        'close' => $item['close'],
    ], $chartSlice);

    return [
        'updated_at' => $dataset['fetched_at'],
        'source' => $dataset['source'] ?? 'Yahoo Finance chart endpoint',
        'symbol' => $dataset['meta']['symbol'] ?? '',
        'index_name' => $dataset['meta']['name'] ?? ($dataset['meta']['symbol'] ?? ''),
        'exchange_name' => $dataset['meta']['exchange_name'] ?? 'SÃ£o Paulo',
        'currency' => $dataset['meta']['currency'] ?? 'BRL',
        'current_price' => (float) ($dataset['meta']['regular_market_price'] ?? $lastClose),
        'last_close' => $lastClose,
        'previous_close' => $previousClose,
        'day_change' => $dayChange,
        'day_change_pct' => $dayChangePct,
        'probabilities' => [
            'gain' => $modelGain,
            'loss' => $modelLoss,
            'empirical_gain' => $empiricalGain,
            'empirical_loss' => $empiricalLoss,
        ],
            'stats' => [
                'lookback_days' => $lookback,
                'average_daily_return' => $avgReturn,
                'recent_bias' => $recentBias,
                'daily_volatility' => $volatility,
                'today_volume' => (int) (($history[count($history) - 1]['volume'] ?? 0)),
                'momentum_20d' => $momentum20d,
                'range_position_60d' => $rangePosition60d,
                'positive_days' => $positiveDays,
            'negative_days' => $negativeDays,
            'flat_days' => $flatDays,
            'high_60d' => max(array_column($chartSlice, 'close')),
            'low_60d' => min(array_column($chartSlice, 'close')),
        ],
        'chart' => $chart,
        'disclaimer' => count($history) < 30
            ? 'Ativo com histÃ³rico curto. Probabilidades estimadas com menor confianÃ§a. NÃ£o representa recomendaÃ§Ã£o financeira.'
            : 'Estimativa baseada em comportamento histÃ³rico e distribuiÃ§Ã£o estatÃ­stica simples. NÃ£o representa recomendaÃ§Ã£o financeira.',
    ];
}

function buildQuotePayload(array $dataset): array
{
    $history = $dataset['history'] ?? [];
    $lastClose = count($history) ? (float) $history[count($history) - 1]['close'] : 0.0;
    $previousClose = (float) ($dataset['meta']['previous_close'] ?? $lastClose);

    return [
        'symbol' => $dataset['meta']['symbol'] ?? '',
        'name' => $dataset['meta']['name'] ?? ($dataset['meta']['symbol'] ?? ''),
        'currency' => $dataset['meta']['currency'] ?? 'BRL',
        'exchange_name' => $dataset['meta']['exchange_name'] ?? 'SÃ£o Paulo',
        'current_price' => (float) ($dataset['meta']['regular_market_price'] ?? $lastClose),
        'last_close' => $lastClose,
        'previous_close' => $previousClose,
        'updated_at' => $dataset['fetched_at'],
    ];
}

function getOpportunityUniverse(): array
{
    return [
        'VALE3', 'ITUB4', 'PETR4', 'PETR3', 'B3SA3', 'BBAS3', 'ABEV3', 'WEGE3',
        'BBDC4', 'RENT3', 'PRIO3', 'JBSS3', 'SUZB3', 'EQTL3', 'RADL3', 'VBBR3',
        'EMBR3', 'GGBR4', 'LREN3', 'CMIG4', 'BPAC11', 'ASAI3', 'UGPA3', 'RDOR3',
        'CSNA3', 'ELET3', 'ELET6', 'RAIL3', 'VIVT3', 'NTCO3',
    ];
}

function getCryptoUniverse(): array
{
    return [
        'BTC-USD',
        'ETH-USD',
        'SOL-USD',
        'BNB-USD',
        'XRP-USD',
        'ADA-USD',
    ];
}

function getStockCatalog(): array
{
    return [
        ['symbol' => 'HGLG11', 'name' => 'CSHG Logistica', 'type' => 'Fundo imobiliario', 'sector' => 'Fundos imobiliarios', 'pays_dividends' => true, 'defensive' => true],
        ['symbol' => 'KNRI11', 'name' => 'Kinea Renda Imobiliaria', 'type' => 'Fundo imobiliario', 'sector' => 'Fundos imobiliarios', 'pays_dividends' => true, 'defensive' => true],
        ['symbol' => 'MXRF11', 'name' => 'Maxi Renda', 'type' => 'Fundo imobiliario', 'sector' => 'Fundos imobiliarios', 'pays_dividends' => true, 'defensive' => false],
        ['symbol' => 'XPML11', 'name' => 'XP Malls', 'type' => 'Fundo imobiliario', 'sector' => 'Fundos imobiliarios', 'pays_dividends' => true, 'defensive' => false],
        ['symbol' => 'VALE3', 'name' => 'Vale', 'type' => 'Acao', 'sector' => 'Mineracao', 'pays_dividends' => true, 'defensive' => false],
        ['symbol' => 'PETR4', 'name' => 'Petrobras PN', 'type' => 'Acao', 'sector' => 'Petroleo e Gas', 'pays_dividends' => true, 'defensive' => false],
        ['symbol' => 'PETR3', 'name' => 'Petrobras ON', 'type' => 'Acao', 'sector' => 'Petroleo e Gas', 'pays_dividends' => true, 'defensive' => false],
        ['symbol' => 'ITUB4', 'name' => 'Itau Unibanco', 'type' => 'Acao', 'sector' => 'Financeiro', 'pays_dividends' => true, 'defensive' => true],
        ['symbol' => 'BBDC4', 'name' => 'Bradesco PN', 'type' => 'Acao', 'sector' => 'Financeiro', 'pays_dividends' => true, 'defensive' => true],
        ['symbol' => 'BBAS3', 'name' => 'Banco do Brasil', 'type' => 'Acao', 'sector' => 'Financeiro', 'pays_dividends' => true, 'defensive' => true],
        ['symbol' => 'BPAC11', 'name' => 'BTG Pactual', 'type' => 'Unit', 'sector' => 'Financeiro', 'pays_dividends' => false, 'defensive' => false],
        ['symbol' => 'B3SA3', 'name' => 'B3', 'type' => 'Acao', 'sector' => 'Financeiro', 'pays_dividends' => true, 'defensive' => true],
        ['symbol' => 'WEGE3', 'name' => 'WEG', 'type' => 'Acao', 'sector' => 'Industrial', 'pays_dividends' => true, 'defensive' => true],
        ['symbol' => 'EMBR3', 'name' => 'Embraer', 'type' => 'Acao', 'sector' => 'Industrial', 'pays_dividends' => false, 'defensive' => false],
        ['symbol' => 'RENT3', 'name' => 'Localiza', 'type' => 'Acao', 'sector' => 'Consumo', 'pays_dividends' => false, 'defensive' => false],
        ['symbol' => 'LREN3', 'name' => 'Lojas Renner', 'type' => 'Acao', 'sector' => 'Consumo', 'pays_dividends' => true, 'defensive' => false],
        ['symbol' => 'ASAI3', 'name' => 'Assai', 'type' => 'Acao', 'sector' => 'Consumo', 'pays_dividends' => false, 'defensive' => true],
        ['symbol' => 'RADL3', 'name' => 'Raia Drogasil', 'type' => 'Acao', 'sector' => 'Saude', 'pays_dividends' => true, 'defensive' => true],
        ['symbol' => 'RDOR3', 'name' => 'Rede DOr', 'type' => 'Acao', 'sector' => 'Saude', 'pays_dividends' => false, 'defensive' => true],
        ['symbol' => 'EQTL3', 'name' => 'Equatorial', 'type' => 'Acao', 'sector' => 'Energia', 'pays_dividends' => true, 'defensive' => true],
        ['symbol' => 'CMIG4', 'name' => 'Cemig', 'type' => 'Acao', 'sector' => 'Energia', 'pays_dividends' => true, 'defensive' => true],
        ['symbol' => 'ELET3', 'name' => 'Eletrobras ON', 'type' => 'Acao', 'sector' => 'Energia', 'pays_dividends' => true, 'defensive' => true],
        ['symbol' => 'ELET6', 'name' => 'Eletrobras PNB', 'type' => 'Acao', 'sector' => 'Energia', 'pays_dividends' => true, 'defensive' => true],
        ['symbol' => 'VIVT3', 'name' => 'Telefonica Brasil', 'type' => 'Acao', 'sector' => 'Telecom', 'pays_dividends' => true, 'defensive' => true],
        ['symbol' => 'SUZB3', 'name' => 'Suzano', 'type' => 'Acao', 'sector' => 'Papel e Celulose', 'pays_dividends' => true, 'defensive' => false],
        ['symbol' => 'CSNA3', 'name' => 'CSN', 'type' => 'Acao', 'sector' => 'Siderurgia', 'pays_dividends' => true, 'defensive' => false],
        ['symbol' => 'GGBR4', 'name' => 'Gerdau PN', 'type' => 'Acao', 'sector' => 'Siderurgia', 'pays_dividends' => true, 'defensive' => false],
        ['symbol' => 'PRIO3', 'name' => 'PRIO', 'type' => 'Acao', 'sector' => 'Petroleo e Gas', 'pays_dividends' => false, 'defensive' => false],
        ['symbol' => 'VBBR3', 'name' => 'Vibra', 'type' => 'Acao', 'sector' => 'Petroleo e Gas', 'pays_dividends' => true, 'defensive' => false],
        ['symbol' => 'UGPA3', 'name' => 'Ultrapar', 'type' => 'Acao', 'sector' => 'Petroleo e Gas', 'pays_dividends' => true, 'defensive' => false],
        ['symbol' => 'RAIL3', 'name' => 'Rumo', 'type' => 'Acao', 'sector' => 'Logistica', 'pays_dividends' => false, 'defensive' => false],
        ['symbol' => 'JBSS3', 'name' => 'JBS', 'type' => 'Acao', 'sector' => 'Alimentos', 'pays_dividends' => true, 'defensive' => true],
        ['symbol' => 'ABEV3', 'name' => 'Ambev', 'type' => 'Acao', 'sector' => 'Bebidas', 'pays_dividends' => true, 'defensive' => true],
        ['symbol' => 'NTCO3', 'name' => 'Natura', 'type' => 'Acao', 'sector' => 'Consumo', 'pays_dividends' => false, 'defensive' => false],
    ];
}

function scoreOpportunity(array $asset): float
{
    $gain = (float) ($asset['probabilities']['gain'] ?? 0.0);
    $loss = (float) ($asset['probabilities']['loss'] ?? 0.0);
    $momentum20d = (float) ($asset['stats']['momentum_20d'] ?? 0.0);
    $recentBias = (float) ($asset['stats']['recent_bias'] ?? 0.0);
    $avgReturn = (float) ($asset['stats']['average_daily_return'] ?? 0.0);
    $dayChangePct = (float) ($asset['day_change_pct'] ?? 0.0);
    $rangePosition = (float) ($asset['stats']['range_position_60d'] ?? 0.5);

    return
        (($gain - $loss) * 100) +
        ($momentum20d * 120) +
        ($recentBias * 160) +
        ($avgReturn * 120) +
        ($dayChangePct * 40) +
        (($rangePosition - 0.5) * 8);
}

function normalCdf(float $x, float $mean, float $stdDev): float
{
    $z = ($x - $mean) / ($stdDev * sqrt(2));
    return 0.5 * (1 + erfApprox($z));
}

function erfApprox(float $x): float
{
    $sign = $x < 0 ? -1 : 1;
    $x = abs($x);
    $a1 = 0.254829592;
    $a2 = -0.284496736;
    $a3 = 1.421413741;
    $a4 = -1.453152027;
    $a5 = 1.061405429;
    $p = 0.3275911;

    $t = 1 / (1 + $p * $x);
    $y = 1 - (((((($a5 * $t) + $a4) * $t) + $a3) * $t + $a2) * $t + $a1) * $t * exp(-$x * $x);
    return $sign * $y;
}
