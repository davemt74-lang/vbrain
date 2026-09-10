<?php
declare(strict_types=1);

final class DashboardDestinationContextService
{
    public function __construct(private PDO $pdo) {}

    public function selected(int $userId): array
    {
        if (!db_table_exists('dashboard_destination_context')) return [];
        $stmt = $this->pdo->prepare('SELECT * FROM dashboard_destination_context WHERE user_id=? AND is_selected=1 ORDER BY updated_at DESC,id DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll() ?: [];
    }

    public function watched(int $userId): array
    {
        if (!db_table_exists('dashboard_destination_context')) return [];
        $stmt = $this->pdo->prepare('SELECT * FROM dashboard_destination_context WHERE user_id=? AND is_watching=1 ORDER BY updated_at DESC,id DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll() ?: [];
    }

    public function setSelected(int $userId, array $input, bool $selected): array
    {
        if (!db_table_exists('dashboard_destination_context')) throw new RuntimeException('Run System Upgrade before saving destination context.');
        $key = $this->clean((string)($input['destination_key'] ?? ''), 190);
        $name = $this->clean((string)($input['destination_name'] ?? ''), 255);
        if ($key === '' || $name === '') throw new InvalidArgumentException('Destination context is incomplete.');
        $type = $this->clean((string)($input['destination_type'] ?? 'destination'), 40) ?: 'destination';
        $url = $this->clean((string)($input['destination_url'] ?? ''), 1500) ?: null;
        $catalogId = max(0, (int)($input['destination_catalog_id'] ?? 0)) ?: null;
        $meta = [
            'location' => $this->clean((string)($input['location'] ?? ''), 255),
            'subtitle' => $this->clean((string)($input['subtitle'] ?? ''), 500),
            'duration' => $this->clean((string)($input['duration'] ?? ''), 120),
        ];
        $stmt = $this->pdo->prepare('INSERT INTO dashboard_destination_context (user_id,destination_key,destination_catalog_id,destination_name,destination_type,destination_url,metadata_json,is_selected,selected_at) VALUES (?,?,?,?,?,?,?, ?, IF(?=1,NOW(),NULL)) ON DUPLICATE KEY UPDATE destination_catalog_id=VALUES(destination_catalog_id),destination_name=VALUES(destination_name),destination_type=VALUES(destination_type),destination_url=VALUES(destination_url),metadata_json=VALUES(metadata_json),is_selected=VALUES(is_selected),selected_at=IF(VALUES(is_selected)=1,NOW(),selected_at),updated_at=NOW()');
        $flag = $selected ? 1 : 0;
        $stmt->execute([$userId,$key,$catalogId,$name,$type,$url,json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$flag,$flag]);
        return $this->selected($userId);
    }

    public function setWatchingForSelected(int $userId, bool $watching): array
    {
        if (!db_table_exists('dashboard_destination_context')) throw new RuntimeException('Run System Upgrade before watching destinations.');
        $stmt = $this->pdo->prepare('UPDATE dashboard_destination_context SET is_watching=?,updated_at=NOW() WHERE user_id=? AND is_selected=1');
        $stmt->execute([$watching?1:0,$userId]);
        return $this->watched($userId);
    }

    public function names(int $userId): array
    {
        return array_values(array_filter(array_map(static fn(array $row): string => trim((string)($row['destination_name'] ?? '')), $this->selected($userId))));
    }

    public function promptContext(int $userId): string
    {
        $rows = $this->selected($userId);
        if (!$rows) return '';
        $parts = [];
        foreach ($rows as $row) {
            $meta = json_decode((string)($row['metadata_json'] ?? ''), true) ?: [];
            $bits = array_values(array_filter([
                (string)($row['destination_name'] ?? ''),
                trim((string)($meta['location'] ?? '')),
                trim((string)($meta['duration'] ?? '')),
            ]));
            if ($bits) $parts[] = implode(' — ', $bits);
        }
        return $parts ? "\n\nDASHBOARD DESTINATION CONTEXT: The user explicitly selected these destinations for the current search/agent task: ".implode('; ', $parts).'. Prefer these places when the request is about comparison, research, planning, recommendations, watching, or vacation photos unless the user clearly asks for something else.' : '';
    }

    private function clean(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/u',' ',$value) ?? $value);
        return function_exists('mb_substr') ? mb_substr($value,0,$max) : substr($value,0,$max);
    }
}
