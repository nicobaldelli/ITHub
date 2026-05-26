<?php

declare(strict_types=1);

namespace ITHub\Api\Validators;

use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\User;

/**
 * Valida y normaliza el payload de creación/edición de usuarios.
 * La validación de password (complejidad) NO se hace acá; se delega a
 * AuthService::validatePasswordStrength cuando el flujo lo requiere.
 */
final class UsuarioValidator
{
    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        $nombre = trim((string) ($data['nombre'] ?? ''));
        if (!$isUpdate || array_key_exists('nombre', $data)) {
            if ($nombre === '' || mb_strlen($nombre) > 100) {
                $errors['nombre'] = 'Requerido, hasta 100 caracteres';
            }
        }

        $apellido = trim((string) ($data['apellido'] ?? ''));
        if (!$isUpdate || array_key_exists('apellido', $data)) {
            if ($apellido === '' || mb_strlen($apellido) > 100) {
                $errors['apellido'] = 'Requerido, hasta 100 caracteres';
            }
        }

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if (!$isUpdate || array_key_exists('email', $data)) {
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
                $errors['email'] = 'Email inválido';
            }
        }

        if (!$isUpdate || array_key_exists('rol', $data)) {
            if (!isset($data['rol']) || !in_array($data['rol'], User::ROLES, true)) {
                $errors['rol'] = 'Permitidos: ' . implode(', ', User::ROLES);
            }
        }

        if (!empty($errors)) {
            throw new ValidationException('Datos inválidos', $errors);
        }

        $clean = [];
        if (array_key_exists('nombre', $data)) $clean['nombre'] = $nombre;
        if (array_key_exists('apellido', $data)) $clean['apellido'] = $apellido;
        if (array_key_exists('email', $data)) $clean['email'] = $email;
        if (array_key_exists('rol', $data)) $clean['rol'] = (string) $data['rol'];
        if (array_key_exists('activo', $data)) $clean['activo'] = (bool) $data['activo'];

        return $clean;
    }
}
