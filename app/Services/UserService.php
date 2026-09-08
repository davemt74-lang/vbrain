<?php
declare(strict_types=1);

final class UserService
{
    public function __construct(private PDO $pdo) {}

    public function registerFromDiagnosis(string $name, string $email, string $password, array $diagnosis): int
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        if ($name === '' || strlen($name) > 120) throw new InvalidArgumentException('Enter your name.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
        if (strlen($password) < 8) throw new InvalidArgumentException('Use at least 8 characters for your password.');

        $this->pdo->beginTransaction();
        try {
            $exists = $this->pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
            $exists->execute([$email]);
            if ($exists->fetch()) throw new InvalidArgumentException('An account already exists for that email.');

            $stmt = $this->pdo->prepare('INSERT INTO users (email,display_name,timezone,status,last_active_at) VALUES (?,?,?,"active",NOW())');
            $stmt->execute([$email,$name,date_default_timezone_get()]);
            $userId = (int)$this->pdo->lastInsertId();

            $hasUsers = (int)$this->pdo->query('SELECT COUNT(*) FROM user_auth')->fetchColumn();
            $firstAccount = $hasUsers === 0;
            $stmt = $this->pdo->prepare('INSERT INTO user_auth (user_id,password_hash,role) VALUES (?,?,?)');
            $stmt->execute([$userId,password_hash($password,PASSWORD_DEFAULT),$firstAccount?'admin':'user']);
            if ($firstAccount && db_table_exists('site_settings')) {
                $this->pdo->prepare('INSERT INTO site_settings (setting_key,setting_value,setting_group,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),setting_group=VALUES(setting_group),updated_at=NOW()')->execute(['installation.owner_user_id',(string)$userId,'system']);
            }
            $this->pdo->prepare('INSERT INTO user_settings (user_id) VALUES (?)')->execute([$userId]);
            $level = $this->scoreLevel((int)$diagnosis['vacation_brain_score']);
            $this->pdo->prepare('INSERT INTO user_score_summary (user_id,vacation_brain_score,score_level) VALUES (?,?,?)')->execute([$userId,$diagnosis['vacation_brain_score'],$level]);

            $traitId = $this->pdo->prepare('SELECT id FROM traits WHERE slug=?');
            $traitInsert = $this->pdo->prepare('INSERT INTO user_traits (user_id,trait_id,score,confidence,interaction_count,last_updated_at) VALUES (?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE score=VALUES(score),confidence=VALUES(confidence),interaction_count=interaction_count+1,last_updated_at=NOW()');
            foreach (($diagnosis['traits'] ?? []) as $slug => $trait) {
                $traitId->execute([$slug]);
                $id = $traitId->fetchColumn();
                if ($id) $traitInsert->execute([$userId,$id,$trait['score'],$trait['confidence']]);
            }

            $event = $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,choice_id,value_numeric) VALUES (?,"choice_selected",?,2)');
            foreach (($diagnosis['answers'] ?? []) as $choiceId) $event->execute([$userId,(int)$choiceId]);
            $this->pdo->prepare('INSERT INTO user_events (user_id,event_type,value_numeric,value_text) VALUES (?,"diagnosis_completed",?,?)')->execute([$userId,$diagnosis['diagnosis_score'],$diagnosis['level']]);

            $result = $this->pdo->prepare('INSERT INTO diagnosis_results (user_id,diagnosis_score,vacation_brain_score,diagnosis_level,diagnosis_title,summary_text,prescription_json,answer_snapshot_json,trait_snapshot_json) VALUES (?,?,?,?,?,?,?,?,?)');
            $result->execute([
                $userId,$diagnosis['diagnosis_score'],$diagnosis['vacation_brain_score'],$diagnosis['level'],$diagnosis['title'],$diagnosis['summary'],
                json_encode($diagnosis['prescription'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                json_encode($diagnosis['answers'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                json_encode($diagnosis['traits'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            ]);

            (new AchievementService($this->pdo))->evaluate($userId);
            $this->pdo->commit();
            return $userId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }


    public function account(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT u.id,u.email,u.username,u.display_name,u.avatar_url,u.timezone,u.country_code,u.status,u.created_at,us.sarcasm_level,us.notification_level,us.location_enabled,us.personalized_discovery_enabled,us.merch_personalization_enabled FROM users u LEFT JOIN user_settings us ON us.user_id=u.id WHERE u.id=? LIMIT 1');
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: [];
    }

    public function updateAccount(int $userId, array $input): void
    {
        $name = trim((string)($input['display_name'] ?? ''));
        $username = strtolower(trim((string)($input['username'] ?? '')));
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $timezone = trim((string)($input['timezone'] ?? date_default_timezone_get()));
        $country = strtoupper(trim((string)($input['country_code'] ?? '')));
        if ($name === '' || strlen($name) > 120) throw new InvalidArgumentException('Enter a display name.');
        if ($username !== '' && !preg_match('/^[a-z0-9._-]{3,40}$/', $username)) throw new InvalidArgumentException('Username must be 3–40 characters using letters, numbers, dots, dashes, or underscores.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
        if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) throw new InvalidArgumentException('Country code must use two letters.');
        if (!in_array($timezone, timezone_identifiers_list(), true)) $timezone = date_default_timezone_get();

        $dup=$this->pdo->prepare('SELECT id FROM users WHERE id<>? AND (email=? OR (?<>"" AND username=?)) LIMIT 1');
        $dup->execute([$userId,$email,$username,$username]);
        if($dup->fetch()) throw new InvalidArgumentException('That email or username is already being used.');

        $this->pdo->prepare('UPDATE users SET display_name=?,username=NULLIF(?,""),email=?,timezone=?,country_code=NULLIF(?,"") WHERE id=?')->execute([$name,$username,$email,$timezone,$country,$userId]);
        $this->pdo->prepare('INSERT INTO user_settings (user_id,sarcasm_level,notification_level,location_enabled,personalized_discovery_enabled,merch_personalization_enabled) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE sarcasm_level=VALUES(sarcasm_level),notification_level=VALUES(notification_level),location_enabled=VALUES(location_enabled),personalized_discovery_enabled=VALUES(personalized_discovery_enabled),merch_personalization_enabled=VALUES(merch_personalization_enabled)')->execute([
            $userId,
            max(0,min(3,(int)($input['sarcasm_level']??2))),
            in_array(($input['notification_level']??'normal'),['quiet','normal','enthusiastic'],true)?$input['notification_level']:'normal',
            !empty($input['location_enabled'])?1:0,
            !empty($input['personalized_discovery_enabled'])?1:0,
            !empty($input['merch_personalization_enabled'])?1:0,
        ]);
    }

    public function updateAvatar(int $userId, string $avatarUrl): void
    {
        $this->pdo->prepare('UPDATE users SET avatar_url=? WHERE id=?')->execute([$avatarUrl,$userId]);

        // The account avatar is also the canonical primary Travel Match photo.
        // Keep existing Travel Match profiles synchronized without requiring another upload.
        try {
            $profile = $this->pdo->prepare('SELECT user_id FROM travel_match_profiles WHERE user_id=? LIMIT 1');
            $profile->execute([$userId]);
            if ($profile->fetchColumn()) {
                $this->pdo->prepare('UPDATE travel_match_profiles SET photo_url=? WHERE user_id=?')->execute([$avatarUrl,$userId]);
                $photo = $this->pdo->prepare('SELECT id FROM travel_match_profile_photos WHERE user_id=? ORDER BY sort_order,id LIMIT 1');
                $photo->execute([$userId]);
                $photoId = $photo->fetchColumn();
                if ($photoId) {
                    $this->pdo->prepare('UPDATE travel_match_profile_photos SET photo_url=?,sort_order=0 WHERE id=?')->execute([$avatarUrl,(int)$photoId]);
                } else {
                    $this->pdo->prepare('INSERT INTO travel_match_profile_photos (user_id,photo_url,sort_order) VALUES (?,?,0)')->execute([$userId,$avatarUrl]);
                }
            }
        } catch (PDOException $e) {
            // Legacy installs may not have Travel Matching tables yet. Account avatar saves must still work.
            $sqlState = (string)$e->getCode();
            if (!in_array($sqlState,['42S02','42S22'],true)) throw $e;
        }
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        if (strlen($newPassword) < 8) throw new InvalidArgumentException('Use at least 8 characters for the new password.');
        $stmt=$this->pdo->prepare('SELECT password_hash FROM user_auth WHERE user_id=?');$stmt->execute([$userId]);$hash=(string)$stmt->fetchColumn();
        if(!$hash || !password_verify($currentPassword,$hash)) throw new InvalidArgumentException('Your current password is incorrect.');
        $this->pdo->prepare('UPDATE user_auth SET password_hash=? WHERE user_id=?')->execute([password_hash($newPassword,PASSWORD_DEFAULT),$userId]);
    }

    public function authenticate(string $email, string $password): ?int
    {
        $stmt = $this->pdo->prepare('SELECT u.id,ua.password_hash FROM users u JOIN user_auth ua ON ua.user_id=u.id WHERE u.email=? AND u.status="active" LIMIT 1');
        $stmt->execute([strtolower(trim($email))]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password,(string)$row['password_hash'])) return null;
        $this->pdo->prepare('UPDATE user_auth SET last_login_at=NOW() WHERE user_id=?')->execute([$row['id']]);
        $this->pdo->prepare('UPDATE users SET last_active_at=NOW() WHERE id=?')->execute([$row['id']]);
        return (int)$row['id'];
    }

    private function scoreLevel(int $score): string
    {
        return match (true) {
            $score < 300 => 'thinking_about_it',
            $score < 500 => 'needs_a_break',
            $score < 700 => 'mentally_packing',
            $score < 900 => 'vacation_brain_activated',
            $score < 1200 => 'checked_out',
            default => 'basically_at_the_airport',
        };
    }
}
