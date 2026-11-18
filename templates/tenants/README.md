# Custom templates per client

Put a client's own templates in `templates/tenants/<slug>/` (the client's slug from the admin),
using the same file names as `templates/site/`. They are only used when **Use custom templates**
is switched on for that client (Clients → Look & Google). Files you don't add keep using the
standard template.

Override just a few blocks by extending the standard template, e.g.
`templates/tenants/jans-klusbedrijf/home.html.twig`:

```twig
{% extends 'site/home.html.twig' %}

{% block body %}
    <p>Something only this client has.</p>
    {{ parent() }}
{% endblock %}
```

- `@site/...` = this client's version if there is one, otherwise the standard one
  (the standard pages extend `@site/base.html.twig`, so a custom `base.html.twig` applies to all pages).
- `site/...` = always the standard template. Extend this one, never `@site/` with the same name
  (that would extend itself).
