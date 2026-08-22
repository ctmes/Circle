<?php

namespace Tests\Support;

use App\Enums\CircleRole;
use App\Enums\ProcessingStatus;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\CircleResource;
use App\Models\EvidenceItem;
use App\Models\EvidenceVersion;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\User;
use Database\Seeders\AgentBlueprintSeeder;
use Illuminate\Support\Str;

/**
 * Fixture helpers. Built by hand rather than with model factories so each test
 * reads as a specific, recognisable situation from the pilot scenario.
 */
trait BuildsCircles
{
    protected function seedBlueprint(): void
    {
        (new AgentBlueprintSeeder())->run();
    }

    protected function makeUser(string $name, string $email): User
    {
        return User::create(['name' => $name, 'email' => $email, 'password' => 'password-for-tests']);
    }

    protected function makeOrganisation(string $name = 'JWA Mats'): Organisation
    {
        return Organisation::create(['name' => $name, 'slug' => Str::slug($name) . '-' . Str::lower(Str::random(5))]);
    }

    protected function makeCircle(Organisation $org, User $owner, array $attributes = []): Circle
    {
        $circle = Circle::create(array_merge([
            'organisation_id' => $org->id,
            'name'            => 'Rail Access Package — Bid Review',
            'purpose'         => 'Assemble and review the bid package.',
            'status'          => 'active',
            'owner_user_id'   => $owner->id,
            'starts_at'       => now(),
            'expires_at'      => now()->addMonths(3),
        ], $attributes));

        OrganisationMembership::firstOrCreate(
            ['organisation_id' => $org->id, 'user_id' => $owner->id],
            ['org_role' => 'member'],
        );

        $this->addMember($circle, $owner, CircleRole::Owner);

        return $circle;
    }

    protected function addMember(Circle $circle, User $user, CircleRole $role, bool $external = false): CircleMembership
    {
        return CircleMembership::updateOrCreate(
            ['circle_id' => $circle->id, 'user_id' => $user->id],
            ['circle_role' => $role, 'is_external' => $external, 'invite_status' => 'active'],
        );
    }

    /**
     * Creates an evidence item already through the pipeline, with extracted
     * text attached — the state the agent and citation flows operate on.
     */
    protected function makeEvidence(
        Circle $circle,
        User $uploader,
        string $name,
        bool $agentRead = true,
        ?string $extractedText = null,
        array $extractionExtra = [],
        string $filename = 'document.pdf',
    ): EvidenceItem {
        $resource = CircleResource::create([
            'circle_id'       => $circle->id,
            'resource_type'   => 'evidence_item',
            'name'            => $name,
            'created_by_type' => 'user',
            'created_by_id'   => $uploader->id,
            'status'          => 'active',
        ]);

        $item = EvidenceItem::create([
            'resource_id'      => $resource->id,
            'origin_status'    => 'authenticated_upload',
            'integrity_status' => 'intact',
            'review_status'    => 'unreviewed',
            'classification'   => 'internal',
            'uploader_user_id' => $uploader->id,
            'agent_read'       => $agentRead,
            'downloadable'     => true,
        ]);

        $version = EvidenceVersion::create([
            'evidence_item_id'      => $item->id,
            'version_number'        => 1,
            'storage_key'           => "circles/{$circle->id}/evidence/{$item->id}/v1/{$filename}",
            'original_filename'     => $filename,
            'mime_type'             => 'application/pdf',
            'byte_size'             => 1024,
            'sha256'                => hash('sha256', $name),
            'metadata_json'         => ['lane' => 'document'],
            'processing_status'     => ProcessingStatus::Ready,
            'extracted_text_status' => $extractedText === null ? ProcessingStatus::Skipped : ProcessingStatus::Ready,
            'preview_status'        => ProcessingStatus::Skipped,
            'transcript_status'     => ProcessingStatus::Skipped,
            'created_by_user_id'    => $uploader->id,
        ]);

        if ($extractedText !== null) {
            \App\Models\DerivedArtifact::create([
                'circle_id'            => $circle->id,
                'parent_resource_type' => 'evidence_version',
                'parent_resource_id'   => $version->id,
                'artifact_type'        => 'extraction',
                'content_json'         => array_merge([
                    'extractor'  => 'test',
                    'text'       => $extractedText,
                    'char_count' => mb_strlen($extractedText),
                ], $extractionExtra),
                'status'               => 'ready',
                'source_manifest_json' => ['evidence_version_id' => $version->id],
            ]);
        }

        return $item->refresh();
    }
}
