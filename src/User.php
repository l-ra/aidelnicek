<?php

declare(strict_types=1);

namespace Aidelnicek;

class User
{
    private const GENDERS = ['male' => 'Muž', 'female' => 'Žena', 'other' => 'Jiné'];
    private const BODY_TYPES = ['slim' => 'Drobná', 'average' => 'Průměrná', 'large' => 'Robustní'];

    public static function getGenderOptions(): array
    {
        return self::GENDERS;
    }

    public static function getBodyTypeOptions(): array
    {
        return self::BODY_TYPES;
    }

    public static function isAdmin(int $userId): bool
    {
        $stmt = Database::get()->prepare('SELECT is_admin FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row !== false && (bool) $row['is_admin'];
    }

    public static function findById(int $id): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare('SELECT id, name, email, gender, age, body_type, dietary_notes, height, weight, diet_goal, is_admin, created_at FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public static function findByEmail(string $email): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public static function create(array $data): int
    {
        $db   = Database::get();
        $stmt = $db->prepare(
            'INSERT INTO users
                (name, email, password_hash, gender, age, body_type, dietary_notes,
                 height, weight, diet_goal, is_admin)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $stmt->execute([
            $data['name'],
            $data['email'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            $data['gender']        ?? null,
            ($data['age'] ?? '') !== '' ? (int) $data['age'] : null,
            $data['body_type']     ?? null,
            ($data['dietary_notes'] ?? '') !== '' ? $data['dietary_notes'] : null,
            ($data['height'] ?? '') !== '' ? (int) $data['height'] : null,
            ($data['weight'] ?? '') !== '' ? (float) $data['weight'] : null,
            ($data['diet_goal'] ?? '') !== '' ? $data['diet_goal'] : null,
        ]);
        return (int) $db->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'UPDATE users SET name = ?, gender = ?, age = ?, body_type = ?, dietary_notes = ?, height = ?, weight = ?, diet_goal = ? WHERE id = ?'
        );
        $stmt->execute([
            $data['name'],
            $data['gender'] ?? null,
            $data['age'] !== '' && $data['age'] !== null ? (int) $data['age'] : null,
            $data['body_type'] ?? null,
            $data['dietary_notes'] ?? null,
            $data['height'] !== '' && $data['height'] !== null ? (int) $data['height'] : null,
            $data['weight'] !== '' && $data['weight'] !== null ? (float) $data['weight'] : null,
            $data['diet_goal'] !== '' ? ($data['diet_goal'] ?? null) : null,
            $id,
        ]);
    }

    public static function updatePassword(int $id, string $newPassword): void
    {
        $db = Database::get();
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, $id]);
    }

    public static function verifyCurrentPassword(int $userId, string $password): bool
    {
        $stmt = Database::get()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row !== false && password_verify($password, $row['password_hash']);
    }

    public static function verifyPassword(string $email, string $password): ?array
    {
        $user = self::findByEmail($email);
        if ($user === null) {
            return null;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }
        unset($user['password_hash']);
        return $user;
    }

    public static function validateRegistration(array $data): array
    {
        $errors = [];

        if (empty(trim($data['name'] ?? ''))) {
            $errors['name'] = 'Jméno je povinné';
        }

        if (empty(trim($data['email'] ?? ''))) {
            $errors['email'] = 'E-mail je povinný';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Neplatný formát e-mailu';
        } elseif (self::findByEmail($data['email']) !== null) {
            $errors['email'] = 'Tento e-mail je již registrován';
        }

        if (empty($data['password'] ?? '')) {
            $errors['password'] = 'Heslo je povinné';
        } elseif (strlen($data['password']) < 8) {
            $errors['password'] = 'Heslo musí mít alespoň 8 znaků';
        }

        $age = $data['age'] ?? '';
        if ($age !== '' && $age !== null) {
            $ageInt = (int) $age;
            if ($ageInt < 1 || $ageInt > 150) {
                $errors['age'] = 'Věk musí být mezi 1 a 150';
            }
        }

        $height = $data['height'] ?? '';
        if ($height !== '' && $height !== null) {
            $h = (int) $height;
            if ($h < 50 || $h > 250) {
                $errors['height'] = 'Výška musí být mezi 50 a 250 cm';
            }
        }

        $weight = $data['weight'] ?? '';
        if ($weight !== '' && $weight !== null) {
            $w = (float) $weight;
            if ($w < 20 || $w > 500) {
                $errors['weight'] = 'Váha musí být mezi 20 a 500 kg';
            }
        }

        return $errors;
    }

    public static function validateProfile(array $data): array
    {
        $errors = [];
        if (empty(trim($data['name'] ?? ''))) {
            $errors['name'] = 'Jméno je povinné';
        }
        $age = $data['age'] ?? '';
        if ($age !== '' && $age !== null) {
            $ageInt = (int) $age;
            if ($ageInt < 1 || $ageInt > 150) {
                $errors['age'] = 'Věk musí být mezi 1 a 150';
            }
        }
        $height = $data['height'] ?? '';
        if ($height !== '' && $height !== null) {
            $h = (int) $height;
            if ($h < 50 || $h > 250) {
                $errors['height'] = 'Výška musí být mezi 50 a 250 cm';
            }
        }
        $weight = $data['weight'] ?? '';
        if ($weight !== '' && $weight !== null) {
            $w = (float) $weight;
            if ($w < 20 || $w > 500) {
                $errors['weight'] = 'Váha musí být mezi 20 a 500 kg';
            }
        }
        return $errors;
    }

    public static function validatePasswordChange(int $userId, array $data): array
    {
        $errors = [];
        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';
        $confirmPassword = $data['new_password_confirm'] ?? '';

        if (empty($currentPassword)) {
            $errors['current_password'] = 'Aktuální heslo je povinné';
        } elseif (!self::verifyCurrentPassword($userId, $currentPassword)) {
            $errors['current_password'] = 'Nesprávné aktuální heslo';
        }

        if (empty($newPassword)) {
            $errors['new_password'] = 'Nové heslo je povinné';
        } elseif (strlen($newPassword) < 8) {
            $errors['new_password'] = 'Nové heslo musí mít alespoň 8 znaků';
        }

        if ($newPassword !== $confirmPassword) {
            $errors['new_password_confirm'] = 'Hesla se neshodují';
        }

        return $errors;
    }
}
