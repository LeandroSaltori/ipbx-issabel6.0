<?php
// IPbx Prisma Telecom - Redirecionamento e Renderização do Módulo de Ramais
if (file_exists(__DIR__ . '/index.html')) {
    readfile(__DIR__ . '/index.html');
} else {
    header("Location: /ramais/");
}
exit;
