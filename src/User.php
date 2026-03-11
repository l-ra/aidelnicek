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

    public static function findById(int $id): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare('SELECT id, name, email, gender, age, body_type, dietary_notes, is_admin, created_at FROM users WHERE id = ?');
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
        $db = Database::get();
        $stmt = $db->prepare(
            'INSERT INTO users (name, email, password_hash, gender, age, body_type, dietary_notes, is_admin) 
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $stmt->execute([
            $data['name'],
            $data['email'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            $data['gender'] ?? null,
            $data['age'] !== '' && $data['age'] !== null ? (int) $data['age'] : null,
            $data['body_type'] ?? null,
            $data['dietary_notes'] ?? null,
        ]);
        return (int) $db->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'UPDATE users SET name = ?, gender = ?, age = ?, body_type = ?, dietary_notes = ? WHERE id = ?'
        );
        $stmt->execute([
            $data['name'],
            $data['gender'] ?? null,
            $data['age'] !== '' && $data['age'] !== null ? (int) $data['age'] : null,
            $data['body_type'] ?? null,
            $data['dietary_notes'] ?? null,
            $id,
        ]);
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
        return $errors;
    }
}
