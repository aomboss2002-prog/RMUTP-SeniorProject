<?php
declare(strict_types=1);

function dashboard_search(PDO $pdo, string $keyword): array
{
    $keyword = trim(mb_substr($keyword, 0, 160));
    if ($keyword === '') return ['students' => [], 'projects' => []];
    $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword) . '%';
    $students = $pdo->prepare("SELECT id, code, first_name, last_name FROM students
        WHERE CONCAT(COALESCE(code, ''), ' ', first_name, ' ', last_name, ' ', COALESCE(major, '')) LIKE ? ESCAPE '!'
        ORDER BY id LIMIT 4");
    $students->execute([$pattern]);
    $projects = $pdo->prepare("SELECT p.id, p.code, p.title FROM projects p
        LEFT JOIN students s ON s.id = p.student_id
        WHERE CONCAT(COALESCE(p.code, ''), ' ', p.title, ' ', COALESCE(s.first_name, ''), ' ', COALESCE(s.last_name, '')) LIKE ? ESCAPE '!'
        ORDER BY p.id LIMIT 4");
    $projects->execute([$pattern]);
    return ['students' => $students->fetchAll(PDO::FETCH_ASSOC), 'projects' => $projects->fetchAll(PDO::FETCH_ASSOC)];
}
