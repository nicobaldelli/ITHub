<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use ITHub\Api\Exceptions\NotFoundException;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\Auditoria;
use ITHub\Api\Models\Cliente;
use ITHub\Api\Models\User;
use ITHub\Api\Repositories\ClienteRepository;
use ITHub\Api\Validators\ClienteValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Lógica de negocio de clientes. Valida, audita y delega persistencia al repository.
 */
final class ClienteService
{
    public function __construct(
        private readonly ClienteRepository $repo,
        private readonly AuditoriaService $audit
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data, User $user, ServerRequestInterface $request): Cliente
    {
        $clean = ClienteValidator::validate($data, isUpdate: false);

        // Único por CUIT
        if ($this->repo->existsByCuit($clean['cuit'])) {
            throw new ValidationException('Ya existe un cliente con ese CUIT', ['cuit' => 'duplicado']);
        }

        $clean['activo'] = $clean['activo'] ?? true;

        $cliente = Cliente::create($clean);

        $this->audit->log($user->id, 'cliente', $cliente->id, Auditoria::ACCION_CREAR, [
            'after' => $cliente->only(array_keys($clean)),
        ], $request);

        return $cliente;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data, User $user, ServerRequestInterface $request): Cliente
    {
        $cliente = $this->repo->findById($id);
        if ($cliente === null) {
            throw new NotFoundException('Cliente no encontrado');
        }

        $clean = ClienteValidator::validate($data, isUpdate: true);

        if (isset($clean['cuit']) && $clean['cuit'] !== $cliente->cuit
            && $this->repo->existsByCuit($clean['cuit'], excludeId: $id)) {
            throw new ValidationException('Ya existe otro cliente con ese CUIT', ['cuit' => 'duplicado']);
        }

        $before = $cliente->only(array_keys($clean));
        $cliente->fill($clean);
        $cliente->save();

        $this->audit->log($user->id, 'cliente', $cliente->id, Auditoria::ACCION_EDITAR, [
            'before' => $before,
            'after' => $cliente->only(array_keys($clean)),
        ], $request);

        return $cliente;
    }

    public function delete(int $id, User $user, ServerRequestInterface $request): void
    {
        $cliente = $this->repo->findById($id);
        if ($cliente === null) {
            throw new NotFoundException('Cliente no encontrado');
        }
        if ($this->repo->hasFacturas($id)) {
            throw new ValidationException(
                'No se puede eliminar: el cliente tiene facturas asociadas. Desactivalo en su lugar.',
                ['cliente_id' => 'tiene facturas']
            );
        }

        $cliente->delete(); // soft delete

        $this->audit->log($user->id, 'cliente', $id, Auditoria::ACCION_ELIMINAR, [
            'soft_delete' => true,
        ], $request);
    }
}
