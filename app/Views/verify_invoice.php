<!-- app/Views/verify_invoice.php -->
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification de facture - <?= $invoice['invoice_number'] ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 40px;
            background: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .valid-badge {
            background: #10b981;
            color: white;
            padding: 10px 20px;
            border-radius: 30px;
            display: inline-block;
            margin-bottom: 20px;
        }
        .invalid-badge {
            background: #ef4444;
            color: white;
            padding: 10px 20px;
            border-radius: 30px;
            display: inline-block;
            margin-bottom: 20px;
        }
        .info {
            margin: 20px 0;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background: #667eea;
            color: white;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <div style="text-align: center;">
            <div class="valid-badge">✓ FACTURE VALIDE</div>
            <h1>Vérification de facture</h1>
        </div>
        
        <div class="info">
            <p><strong>Numéro facture :</strong> <?= $invoice['invoice_number'] ?></p>
            <p><strong>Date :</strong> <?= date('d/m/Y H:i', strtotime($invoice['invoice_date'])) ?></p>
            <p><strong>Client :</strong> <?= $invoice['customer_name'] ?></p>
            <p><strong>NIF Client :</strong> <?= $invoice['customer_TIN'] ?: '-' ?></p>
            <p><strong>Montant total :</strong> <?= number_format($invoice['total_amount'], 0, ',', ' ') ?> FBu</p>
            <p><strong>Statut paiement :</strong> <?= $invoice['payment_status'] ?></p>
            <p><strong>Statut EBMS :</strong> <?= $invoice['ebms_status'] ?></p>
            <?php if ($invoice['ebms_registered_number']): ?>
            <p><strong>N° enregistrement EBMS :</strong> <?= $invoice['ebms_registered_number'] ?></p>
            <?php endif; ?>
        </div>
        
        <h3>Détail des produits</h3>
        <table>
            <thead>
                <tr><th>Produit</th><th>Quantité</th><th>Prix unitaire</th><th>Total</th></tr>
            </thead>
            <tbody>
                <?php foreach ($invoice['items'] as $item): ?>
                <tr>
                    <td><?= $item['item_designation'] ?></td>
                    <td><?= $item['quantity'] ?></td>
                    <td><?= number_format($item['unit_price'], 0, ',', ' ') ?> FBu</td>
                    <td><?= number_format($item['total_amount'], 0, ',', ' ') ?> FBu</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="footer">
            <p>Cette facture est authentique et a été émise par MUHIZI BLESSED COMPANY</p>
            <p>Vérification effectuée le <?= date('d/m/Y H:i:s') ?></p>
        </div>
    </div>
</body>
</html>