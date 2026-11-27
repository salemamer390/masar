disposable-email-domains.txt: one domain per line, lowercase, sorted. Used by App\Rules\NotDisposableEmail.
Sources merged and de-duplicated on 27/11/2026 (all open-source community lists):
- github.com/disposable-email-domains/disposable-email-domains (curated, strict)
- github.com/disposable/disposable-email-domains (large aggregate)
- github.com/FGRibreau/mailchecker
- github.com/wesbos/burner-email-providers
- github.com/7c/fakefilter
Removed on purpose: real mail services (me.com, duck.com) and every .edu, .gov, .mil and .eg domain (schools, government and Egyptian domains are never blocked).
To update: download newer copies, merge, lowercase, sort -u, and replace this file. Add your own domains as extra lines. No code change is needed.
Major real providers (gmail, yahoo, outlook, hotmail, icloud, proton, ...) are also kept in a code allowlist, so a bad list update can never block them.
