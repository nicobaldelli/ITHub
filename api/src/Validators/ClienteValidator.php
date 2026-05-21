<?php

declare(strict_types=1);

namespace ITHub\Api\Validators;

use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Helpers\CuitValidator;
use ITHub\Api\Models\FacturaVenta;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validator as v;

/**
 * Valida y normaliza el payload de creación/edición de clientes.
 */
final class ClienteValidator
{
    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed> datos normalizados y limpios para persistir
     * @throws ValidationException
     */
    public static function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        // Razón social
        $razonSocial = trim((string) ($data['razon_social'] ?? ''));
        if (!$isUpdate || array_key_exists('razon_social', $data)) {
            if ($razonSocial === '' || mb_strlen($razonSocial) > 200) {
                $errors['razon_social'] = 'Requerida, hasta 200 caracteres';
            }
        }

        // CUIT — siempre validar checksum si viene
        $cuit = isset($data['cuit']) ? (string) $data['cuit'] : '';
        if (!$isUpdate || array_key_exists('cuit', $data)) {
            if ($cuit === '' || !CuitValidator::isValid($cuit)) {
                $errors['cuit'] = 'CUIT inválido (verificá los 11 dígitos y el checksum)';
            }
        }

        // Tipo default
        if (isset($data['tipo_default']) && $data['tipo_default'] !== null && $data['tipo_default'] !== '') {
            if (!in_array($data['tipo_default'], FacturaVenta::TIPOS, true)) {
                $errors['tipo_default'] = 'Tipo inválido. Permitidos: ' . implode(', ', FacturaVenta::TIPOS);
            }
        }

        // Plazo de pago
        if (isset($data['plazo_pago_default']) && $data['plazo_pago_default'] !== null && $data['plazo_pago_default'] !== '') {
            if (!is_numeric($data['plazo_pago_default']) || (int) $data['plazo_pago_default'] < 0 || (int) $data['plazo_pago_default'] > 3650) {
                $errors['plazo_pago_default'] = 'Debe ser un entero entre 0 y 3650 días';
            }
        }

        // CBU (22 dígitos)
        if (isset($data['cbu']) && $data['cbu'] !== null && $data['cbu'] !== '') {
            $cbu = preg_replace('/\D/', '', (string) $data['cbu']) ?? '';
            if (strlen($cbu) !== 22) {
                $errors['cbu'] = 'CBU debe tener 22 dígitos';
            }
        }

        // Emails
        foreach (['mail_envio_factura', 'mail_gestion_cobranza'] as $field) {
            if (isset($data[$field]) && $data[$field] !== null && $data[$field] !== '') {
                if (!filter_var($data[$field], FILTER_VALIDATE_EMAIL)) {
                    $errors[$field] = 'Email inválido';
                }
            }
        }

        // Strings con límite de largo
        $maxLengths = [
            'cuit_pais' => 20,
            'direccion' => 255,
            'banco' => 100,
            'alias' => 30,
            'mail_envio_factura' => 150,
            'contacto_envio_factura' => 150,
            'telefono_contacto_proveedores' => 50,
            'mail_gestion_cobranza' => 150,
            'contacto_gestion_cobranza' => 150,
            'telefono_contacto_cobranza' => 50,
        ];
        foreach ($maxLengths as $field => $max) {
            if (isset($data[$field]) && is_string($data[$field]) && mb_strlen($data[$field]) > $max) {
                $errors[$field] = "Máximo {$max} caracteres";
            }
        }

        // Observaciones: chequeo anti-script
        if (isset($data['observaciones']) && is_string($data['observaciones'])) {
            if (preg_match('/<\s*(script|iframe|object|embed)\b/i', $data['observaciones'])) {
                $errors['observaciones'] = 'Contenido no permitido';
            }
        }

        if (!empty($errors)) {
            throw new ValidationException('Datos inválidos', $errors);
        }

        // Normalización
        $clean = [];
        foreach ([
            'razon_social', 'cuit_pais', 'tipo_default', 'direccion', 'banco', 'alias',
            'mail_envio_factura', 'contacto_envio_factura', 'telefono_contacto_proveedores',
            'mail_gestion_cobranza', 'contacto_gestion_cobranza', 'telefono_contacto_cobranza',
            'observaciones',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $clean[$field] = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
                if ($clean[$field] === '') {
                    $clean[$field] = null;
                }
            }
        }

        if (array_key_exists('cuit', $data)) {
            $clean['cuit'] = CuitValidator::normalize($cuit);
        }
        if (array_key_exists('cbu', $data) && $data['cbu'] !== null && $data['cbu'] !== '') {
            $clean['cbu'] = preg_replace('/\D/', '', (string) $data['cbu']);
        } elseif (array_key_exists('cbu', $data)) {
            $clean['cbu'] = null;
        }

        if (array_key_exists('plazo_pago_default', $data)) {
            $clean['plazo_pago_default'] = ($data['plazo_pago_default'] === null || $data['plazo_pago_default'] === '')
                ? null
                : (int) $data['plazo_pago_default'];
        }

        if (array_key_exists('activo', $data)) {
            $clean['activo'] = (bool) $data['activo'];
        }

        return $clean;
    }
}
