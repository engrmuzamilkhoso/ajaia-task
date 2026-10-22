<?php
/** @var string $pageTitle @var string $bodyClass */
$pageTitle = $pageTitle ?? 'Ajaia Docs';
$bodyClass = $bodyClass ?? '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="app-base" content="<?= e(base_path()) ?>">
    <title><?= e($pageTitle) ?> · Ajaia Docs</title>
    <link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="<?= e($bodyClass) ?>">
