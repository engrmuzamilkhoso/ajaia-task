-- Demo data. Every account's password is: demo1234
-- INSERT IGNORE keeps this idempotent.

INSERT IGNORE INTO users (id, name, email, password_hash) VALUES
    (1, 'Alice Morgan', 'alice@ajaia.demo', '$2y$10$54OGZ9f7qztCyhe2nMiCoumoHEth49tsewZyUpr2Ji227Tu7DJRn6'),
    (2, 'Bob Chen',     'bob@ajaia.demo',   '$2y$10$54OGZ9f7qztCyhe2nMiCoumoHEth49tsewZyUpr2Ji227Tu7DJRn6'),
    (3, 'Carol Diaz',   'carol@ajaia.demo', '$2y$10$54OGZ9f7qztCyhe2nMiCoumoHEth49tsewZyUpr2Ji227Tu7DJRn6');

INSERT IGNORE INTO documents (id, title, content, owner_id) VALUES
    (1, 'Welcome to Ajaia Docs',
     '<h1>Welcome to Ajaia Docs</h1><p>This document is owned by <strong>Alice</strong> and shared with <strong>Bob</strong>, so both of them can edit it.</p><h2>Things to try</h2><ul><li>Format text with <strong>bold</strong>, <em>italic</em> and <u>underline</u></li><li>Add headings and lists from the toolbar</li><li>Import a .txt, .md or .docx file from the dashboard</li></ul><ol><li>Make a change</li><li>Wait for <em>Saved</em></li><li>Refresh the page &mdash; your change is still here</li></ol>',
     1),
    (2, 'Q3 planning notes (private)',
     '<h2>Q3 planning</h2><p>Only Alice can open this document. Sign in as Bob or Carol and try opening it by URL &mdash; access is refused by the server.</p>',
     1);

INSERT IGNORE INTO document_shares (document_id, user_id, permission) VALUES
    (1, 2, 'editor');
