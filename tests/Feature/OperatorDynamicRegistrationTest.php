<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperatorDynamicRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_registration_persists_company_and_representative_fields_and_exposes_them_in_profile_and_dashboard(): void
    {
        $this->seed();

        $firstRegistration = $this->postJson('/api/v1/provider/register', [
            'name' => 'MARIA OPERADORA',
            'email' => 'maria@aerolineauno.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'provider',
            'company_name' => 'AEROLINEA UNO',
            'commercial_name' => 'AEROLINEA UNO',
            'legal_name' => 'AEROLINEA UNO SA DE CV',
            'rfc' => 'AUN010101AAA',
            'company_phone' => '+525500000001',
            'company_email' => 'operaciones@aerolineauno.test',
            'base_airport' => 'MMMX',
            'representative_name' => 'MARIA OPERADORA',
            'representative_phone' => '+525511111111',
            'birth_date' => '1988-02-10',
            'curp' => 'OPEM880210MDFRRR01',
            'nationality' => 'Mexicana',
            'document_type' => 'INE',
            'document_number' => 'DOC-UNO-001',
            'document_expiration' => '2031-08-15',
        ]);

        $secondRegistration = $this->postJson('/api/v1/provider/register', [
            'name' => 'JUAN OPERADOR',
            'email' => 'juan@jetsdos.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'provider',
            'company_name' => 'JETS DOS',
            'commercial_name' => 'JETS DOS',
            'legal_name' => 'JETS DOS SA DE CV',
            'rfc' => 'JDO010101BBB',
            'company_phone' => '+525500000002',
            'company_email' => 'ops@jetsdos.test',
            'base_airport' => 'MMGL',
            'representative_name' => 'JUAN OPERADOR',
            'representative_phone' => '+525522222222',
            'birth_date' => '1985-07-21',
            'curp' => 'OPEJ850721HDFRRR02',
            'nationality' => 'Mexicana',
            'document_type' => 'Pasaporte',
            'document_number' => 'DOC-DOS-002',
            'document_expiration' => '2032-01-30',
        ]);

        $firstRegistration
            ->assertCreated()
            ->assertJsonPath('user.provider.company_name', 'AEROLINEA UNO')
            ->assertJsonPath('user.provider.representative_name', 'MARIA OPERADORA');

        $secondRegistration
            ->assertCreated()
            ->assertJsonPath('user.provider.company_name', 'JETS DOS')
            ->assertJsonPath('user.provider.representative_name', 'JUAN OPERADOR');

        $firstToken = $firstRegistration->json('token');
        $secondToken = $secondRegistration->json('token');

        $this->withToken($firstToken)
            ->getJson('/api/v1/provider/profile-status')
            ->assertOk()
            ->assertJsonPath('company.company_name', 'AEROLINEA UNO')
            ->assertJsonPath('company.commercial_name', 'AEROLINEA UNO')
            ->assertJsonPath('company.legal_name', 'AEROLINEA UNO SA DE CV')
            ->assertJsonPath('company.rfc', 'AUN010101AAA')
            ->assertJsonPath('company.company_phone', '+525500000001')
            ->assertJsonPath('company.company_email', 'operaciones@aerolineauno.test')
            ->assertJsonPath('company.base_airport', 'MMMX')
            ->assertJsonPath('company.representative_name', 'MARIA OPERADORA')
            ->assertJsonPath('company.representative_phone', '+525511111111')
            ->assertJsonPath('company.curp', 'OPEM880210MDFRRR01')
            ->assertJsonPath('company.document_type', 'INE')
            ->assertJsonPath('company.document_number', 'DOC-UNO-001')
            ->assertJsonPath('company.document_expiration', '2031-08-15')
            ->assertJsonPath('company.status', 'pending');

        $this->withToken($secondToken)
            ->getJson('/api/v1/provider/profile-status')
            ->assertOk()
            ->assertJsonPath('company.company_name', 'JETS DOS')
            ->assertJsonPath('company.commercial_name', 'JETS DOS')
            ->assertJsonPath('company.legal_name', 'JETS DOS SA DE CV')
            ->assertJsonPath('company.rfc', 'JDO010101BBB')
            ->assertJsonPath('company.company_phone', '+525500000002')
            ->assertJsonPath('company.company_email', 'ops@jetsdos.test')
            ->assertJsonPath('company.base_airport', 'MMGL')
            ->assertJsonPath('company.representative_name', 'JUAN OPERADOR')
            ->assertJsonPath('company.representative_phone', '+525522222222')
            ->assertJsonPath('company.curp', 'OPEJ850721HDFRRR02')
            ->assertJsonPath('company.document_type', 'Pasaporte')
            ->assertJsonPath('company.document_number', 'DOC-DOS-002')
            ->assertJsonPath('company.document_expiration', '2032-01-30')
            ->assertJsonPath('company.status', 'pending');

        $this->withToken($firstToken)
            ->getJson('/api/v1/proveedor/dashboard')
            ->assertOk()
            ->assertJsonPath('provider.company_name', 'AEROLINEA UNO')
            ->assertJsonPath('provider.representative_name', 'MARIA OPERADORA');

        $this->withToken($secondToken)
            ->getJson('/api/v1/proveedor/dashboard')
            ->assertOk()
            ->assertJsonPath('provider.company_name', 'JETS DOS')
            ->assertJsonPath('provider.representative_name', 'JUAN OPERADOR');
    }
}
