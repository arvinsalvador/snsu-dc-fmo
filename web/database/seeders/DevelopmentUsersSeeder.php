<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\EmploymentType;
use App\Enums\PersonnelStatus;
use App\Models\FmoPersonnel;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Seeder;

class DevelopmentUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $password = env('DEMO_SEED_PASSWORD', 'development-only-password');

        foreach ($this->accounts() as $account) {
            $user = User::updateOrCreate(
                ['email' => $account['email']],
                ['name' => $account['name'], 'user_type' => $account['type'], 'password' => $password],
            );
            $user->forceFill([
                'status' => AccountStatus::Approved,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'approved_at' => $user->approved_at ?? now(),
            ])->save();
            $user->syncRoles($account['roles']);

            if (! empty($account['profile'])) {
                $user->requesterProfile()->updateOrCreate(
                    ['user_id' => $user->id],
                    $account['profile'],
                );
            }
        }

        foreach ($this->personnel() as $index => $record) {
            $user = User::where('email', $record['email'])->firstOrFail();
            $personnel = FmoPersonnel::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'personnel_identifier' => sprintf('FMO-DEV-%03d', $index + 1),
                    'designation' => $record['designation'],
                    'employment_type' => $record['employment_type'],
                    'personnel_status' => PersonnelStatus::Active,
                    'notes' => 'Development sample personnel record.',
                    'archived_at' => null,
                ],
            );

            $skills = Skill::whereIn('name', $record['skills'])->get()->keyBy('name');
            $personnel->skills()->sync($skills->mapWithKeys(fn (Skill $skill, string $name): array => [
                $skill->id => ['is_primary' => $name === $record['primary']],
            ])->all());
        }
    }

    /** @return list<array<string, mixed>> */
    private function accounts(): array
    {
        $requesters = [
            ['Joey G. Escauso', 'joey.escauso@example.test', 'faculty', ['Campus Director', 'Requester'], ['department' => 'Campus Administration']],
            ['Kier L. Ecle', 'kier.ecle@example.test', 'faculty', ['Director for Instruction', 'Requester'], ['department' => 'Instruction']],
            ['Arvin S. Salvador', 'arvin.salvador@example.test', 'faculty', ['FMO Head'], ['department' => 'Facilities Management Office']],
            ['Segundino S. Mahinay Jr.', 'segundino.mahinay@example.test', 'faculty', ['Requester'], ['department' => 'Academic Affairs']],
            ['Ian Kim T. Paler', 'ian.paler@example.test', 'faculty', ['Requester'], ['department' => 'Academic Affairs']],
            ['Edgar Sulapas', 'edgar.sulapas@example.test', 'staff', ['Requester'], ['organizational_office' => 'Administrative Services']],
            ['Floria C. Dialde', 'floria.dialde@example.test', 'faculty', ['Requester'], ['department' => 'Student Services']],
            ['Rogerick Arcaya', 'rogerick.arcaya@example.test', 'staff', ['Requester'], ['organizational_office' => 'Administrative Services']],
            ['Rex C. Legaspi', 'rex.legaspi@example.test', 'faculty', ['Requester'], ['department' => 'Quality Assurance']],
            ['Bettie Barcinilla', 'bettie.barcinilla@example.test', 'staff', ['Requester'], ['organizational_office' => 'Food Innovation Center']],
            ['Jessa Jean Pande', 'jessa.pande@example.test', 'faculty', ['Requester'], ['department' => 'Culture and Arts']],
        ];
        $personnel = collect($this->personnel())->map(fn (array $record): array => [
            $record['name'], $record['email'], 'staff', $record['roles'], ['organizational_office' => 'Facilities Management Office'],
        ])->all();

        return collect([...$requesters, ...$personnel])->map(fn (array $account): array => [
            'name' => $account[0], 'email' => $account[1], 'type' => $account[2],
            'roles' => $account[3], 'profile' => $account[4],
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function personnel(): array
    {
        return [
            ['name' => 'Mielven Andamon', 'email' => 'mielven.andamon@example.test', 'roles' => ['FMO Staff'], 'designation' => 'FMO Staff', 'employment_type' => EmploymentType::JobOrder, 'primary' => 'Welding', 'skills' => ['Welding', 'Fabrication', 'General Maintenance', 'Grounds Maintenance', 'Painting', 'Plumbing', 'Other']],
            ['name' => 'Russel F. Curada', 'email' => 'russel.curada@example.test', 'roles' => ['FMO Staff'], 'designation' => 'FMO Staff', 'employment_type' => EmploymentType::JobOrder, 'primary' => 'Carpentry / Woodworking', 'skills' => ['Carpentry / Woodworking', 'General Maintenance', 'Painting', 'Masonry', 'Plumbing', 'Other']],
            ['name' => 'Kevin A. Dacera', 'email' => 'kevin.dacera@example.test', 'roles' => ['FMO Staff', 'FMO Oversight'], 'designation' => 'FMO Staff / Oversight', 'employment_type' => EmploymentType::JobOrder, 'primary' => 'General Maintenance', 'skills' => ['General Maintenance', 'Fabrication', 'Grounds Maintenance', 'Painting', 'Plumbing', 'Other']],
            ['name' => 'Ronald Dulguime', 'email' => 'ronald.dulguime@example.test', 'roles' => ['FMO Staff', 'FMO Dispatcher'], 'designation' => 'FMO Dispatcher', 'employment_type' => EmploymentType::JobOrder, 'primary' => 'General Maintenance', 'skills' => ['General Maintenance', 'Painting', 'Plumbing', 'Grounds Maintenance', 'Other']],
            ['name' => 'Chimbhaco D. Ferol', 'email' => 'chimbhaco.ferol@example.test', 'roles' => ['FMO Staff'], 'designation' => 'FMO Staff', 'employment_type' => EmploymentType::JobOrder, 'primary' => 'Grounds Maintenance', 'skills' => ['Grounds Maintenance', 'General Maintenance', 'Painting', 'Plumbing', 'Other']],
            ['name' => 'Gary R. Pascual', 'email' => 'gary.pascual@example.test', 'roles' => ['FMO Staff'], 'designation' => 'FMO Staff', 'employment_type' => EmploymentType::JobOrder, 'primary' => 'Masonry', 'skills' => ['Masonry', 'Carpentry / Woodworking', 'Plumbing', 'Painting', 'Fabrication', 'General Maintenance', 'Other']],
            ['name' => 'Blas C. Vicio Jr.', 'email' => 'blas.vicio@example.test', 'roles' => ['FMO Staff'], 'designation' => 'FMO Staff', 'employment_type' => EmploymentType::JobOrder, 'primary' => 'Electrical', 'skills' => ['Electrical', 'Painting', 'General Maintenance', 'Grounds Maintenance', 'Air Conditioning', 'Other']],
            ['name' => 'Jerico C. Nobleza', 'email' => 'jerico.nobleza@example.test', 'roles' => ['FMO Staff'], 'designation' => 'FMO Staff', 'employment_type' => EmploymentType::JobOrder, 'primary' => 'Electrical', 'skills' => ['Electrical', 'Painting', 'General Maintenance', 'Grounds Maintenance', 'Air Conditioning', 'Other']],
            ['name' => 'Elmer O. Toldo', 'email' => 'elmer.toldo@example.test', 'roles' => ['FMO Staff'], 'designation' => 'Campus Driver', 'employment_type' => EmploymentType::Contractual, 'primary' => 'Driver', 'skills' => ['Driver', 'General Maintenance', 'Painting', 'Plumbing', 'Other']],
        ];
    }
}
