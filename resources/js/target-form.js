// Formulaire d'une target (SCRUM-69) : n'affiche que les champs du type choisi.
// Les champs des autres types sont désactivés : ils ne sont ni envoyés ni bloqués par l'attribut "required".
// La vue rend déjà le bon état au chargement ; ce script ne sert qu'au changement de type.
document.addEventListener('change', (event) => {
    if (!event.target.matches('input[name="type"]')) {
        return;
    }

    const type = event.target.value;

    event.target.form.querySelectorAll('[data-type-fields]').forEach((section) => {
        const visible = section.dataset.typeFields.split(' ').includes(type);

        section.classList.toggle('d-none', !visible);
        section.querySelectorAll('input, select, textarea').forEach((field) => {
            field.disabled = !visible;
        });
    });
});
