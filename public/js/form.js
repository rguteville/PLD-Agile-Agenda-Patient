document.querySelectorAll('form[data-ajax]').forEach(form => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const url = form.action;
        const options = {
            method: 'POST',
            body: new FormData(form),
        }
        const response = await fetch(url, options);
        const data = await response.json();

        console.log(data);
        // data.success / data.errors à traiter ici
    });
});