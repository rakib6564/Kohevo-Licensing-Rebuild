<?php
/**
 * Slate — ContentPageRepository: the owning row for admin/editor.php's Phase 2
 * pages (migration 0019_content_pages).
 *
 * Deliberately thin — addressing data only (tenant, type, title, slug, status).
 * The document tree is never read or written here; that is RevisionStore's job
 * via ContentPublicationService. Tenant scoping is automatic via the base
 * Repository, matching the "no raw SQL in presentation code" rule — admin/
 * editor.php and admin/posts.php go through this class, never Database:: directly.
 */

declare(strict_types=1);

namespace Slate\Services\Content;

use Slate\Data\Repository;

final class ContentPageRepository extends Repository
{
    /** The owner_type value content_pages rows use in RevisionStore/CompilationStore/DependencyStore. */
    public const OWNER_TYPE = 'content_pages';

    protected string $table = 'content_pages';

    /** Create a new page row; returns the new id. */
    public function create(string $type, string $title, string $slug): int
    {
        return $this->insert([
            'type'  => $type,
            'title' => $title,
            'slug'  => $slug,
        ]);
    }

    /** Update editable meta fields on an existing page (title/slug only). */
    public function updateMeta(int $id, array $fields): void
    {
        $data = [];
        if (isset($fields['title']) && $fields['title'] !== '') {
            $data['title'] = (string) $fields['title'];
        }
        if (isset($fields['slug']) && $fields['slug'] !== '') {
            $data['slug'] = (string) $fields['slug'];
        }
        if ($data === []) {
            return;
        }
        $data['updated_at'] = \slate_db_now();
        $this->update($id, $data);
    }

    /** Flip publication status on the owning row (revision publishing is separate). */
    public function markPublished(int $id): void
    {
        $this->update($id, ['status' => 'published', 'updated_at' => \slate_db_now()]);
    }

    /** Return one tenant-scoped page by its public slug. */
    public function findBySlug(string $slug): ?array
    {
        $slug = trim($slug, '/');
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9\/-]{0,190}$/i', $slug)) return null;
        return $this->query()->where('slug', $slug)->where('status', 'published')->first();
    }

    /**
     * @param array{type?:string,search?:string} $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $qb = $this->query();
        $type = trim((string) ($filters['type'] ?? ''));
        if ($type !== '') {
            $qb->where('type', $type);
        }
        $term = trim((string) ($filters['search'] ?? ''));
        if ($term !== '') {
            $qb->where('title', 'LIKE', '%' . $term . '%');
        }
        return $qb->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->limit($limit)->offset($offset)->get();
    }
}
