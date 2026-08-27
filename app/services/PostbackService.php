function fireAffiliatePostback(PDO $pdo, array $conversion)
{
    /*
    $conversion must include:
    affiliate_id, offer_id, click_id,
    conversion_id, payout, status
    */

    /* 1️⃣ OFFER LEVEL FIRST */
    $stmt = $pdo->prepare("
        SELECT postback_url
        FROM affiliate_offer_postbacks
        WHERE affiliate_id = :aid
          AND offer_id = :oid
          AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([
        'aid' => $conversion['affiliate_id'],
        'oid' => $conversion['offer_id']
    ]);
    $postback = $stmt->fetchColumn();

    /* 2️⃣ FALLBACK TO GLOBAL */
    if (!$postback) {
        $stmt = $pdo->prepare("
            SELECT postback_url
            FROM affiliate_postbacks
            WHERE affiliate_id = :aid
              AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute(['aid' => $conversion['affiliate_id']]);
        $postback = $stmt->fetchColumn();
    }

    if (!$postback) {
        return; // nothing to fire
    }

    /* 3️⃣ TOKEN REPLACEMENT */
    $replace = [
        '{click_id}'      => $conversion['click_id'],
        '{conversion_id}' => $conversion['conversion_id'],
        '{offer_id}'      => $conversion['offer_id'],
        '{affiliate_id}'  => $conversion['affiliate_id'],
        '{payout}'        => $conversion['payout'],
        '{status}'        => $conversion['status'],
        '{currency}'      => 'USD'
    ];

    $finalUrl = str_replace(
        array_keys($replace),
        array_values($replace),
        $postback
    );

    /* 4️⃣ FIRE (NON-BLOCKING) */
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $finalUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    curl_exec($ch);
    curl_close($ch);
}
