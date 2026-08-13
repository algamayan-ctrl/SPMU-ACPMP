<?php

namespace Database\Seeders;

use App\Models\OrganizationalUnit;
use Illuminate\Database\Seeder;

class OrganizationalUnitSeeder extends Seeder
{
    public function run(): void
    {
        $institution = OrganizationalUnit::query()->firstOrCreate(
            ['unit_code' => 'CSPC'],
            [
                'unit_name' => 'Camarines Sur Polytechnic Colleges',
                'unit_type' => 'INSTITUTION',
                'active' => true,
            ],
        );

        foreach ($this->operationalUnits() as $code => $name) {
            OrganizationalUnit::query()->firstOrCreate(
                ['unit_code' => $code],
                [
                    'parent_unit_id' => $institution->id,
                    'unit_name' => $name,
                    'unit_type' => 'OPERATIONAL_UNIT',
                    'active' => true,
                ],
            );
        }
    }

    /** @return array<string, string> */
    private function operationalUnits(): array
    {
        return [
            'SPMU' => 'Supply and Property Management Unit',
            'GSU' => 'General Services Unit',
            'VPAF' => 'Office of the Vice President for Administration and Finance',
            'ICTU' => 'Information and Communications Technology Unit',
        ];
    }
}
