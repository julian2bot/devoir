document.addEventListener('DOMContentLoaded', function() {

    // --- Variables globales et état de l'application ---
    let calendar;
    let allHomeworks = [];
    const colors = ['#EF4444', '#F97316', '#84CC16', '#22C55E', '#14B8A6', '#0EA5E9', '#6366F1', '#8B5CF6', '#D946EF', '#EC4899'];

    // --- Éléments du DOM ---
    const addHomeworkBtn = document.getElementById('add-homework-btn');
    const upcomingHomeworkList = document.getElementById('upcoming-homework-list');
    const loader = document.getElementById('loader');
    const homeworkModal = document.getElementById('homework-modal');
    const homeworkModalContent = document.getElementById('homework-modal-content');
    const closeModalBtn = document.getElementById('close-modal-btn');
    const homeworkForm = document.getElementById('homework-form');
    const modalTitle = document.getElementById('modal-title');
    const deleteHomeworkBtn = document.getElementById('delete-homework-btn');
    
    // --- INITIALISATION DE L'APPLICATION ---
    
    function initializeCalendar() {
        const calendarEl = document.getElementById('calendar');
        calendar = new FullCalendar.Calendar(calendarEl, {
            locale: 'fr',
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,listWeek'
            },
            buttonText: {
                today: "Aujourd'hui",
                month: 'Mois',
                week: 'Semaine',
                list: 'Liste'
            },
            events: [],
            dateClick: function(info) {
                openHomeworkModal(null, info.dateStr);
            },
            eventClick: function(info) {
                const homework = allHomeworks.find(h => h.id == info.event.id);
                if(homework) openHomeworkModal(homework);
            }
        });
        calendar.render();
    }

    async function loadInitialData() {
        try {
            const response = await fetch('api.php?action=get_all_homework');
            if (!response.ok) throw new Error('Erreur réseau ou serveur.');
            const result = await response.json();
            
            if (result.status === 'success') {
                allHomeworks = result.data;
                renderAll();
            } else {
                throw new Error(result.message || 'Erreur lors du chargement des données.');
            }
        } catch (error) {
            console.error(error);
            upcomingHomeworkList.innerHTML = `<p class="text-red-500 text-center">Impossible de charger les devoirs.</p>`;
        } finally {
            loader.style.display = 'none';
        }
    }

    // --- FONCTIONS DE RENDU ---
        
    function renderAll() {
        renderUpcomingHomework();
        renderCalendarEvents();
    }

    function renderUpcomingHomework() {
        upcomingHomeworkList.innerHTML = '';
        const now = luxon.DateTime.now().startOf('day');
        const fourDaysLater = now.plus({ days: 4 });

        const upcoming = allHomeworks
            .filter(h => {
                const dueDate = luxon.DateTime.fromISO(h.due_date).startOf('day');
                return dueDate >= now && dueDate <= fourDaysLater;
            });
        
        if (upcoming.length === 0) {
            upcomingHomeworkList.innerHTML = `<p class="text-center text-slate-500 p-8 bg-slate-100 rounded-lg">Aucun devoir dans les 4 prochains jours.</p>`;
        } else {
             upcoming.forEach(renderSingleHomeworkCard);
        }
    }

    function renderSingleHomeworkCard(homework) {
        const card = document.createElement('div');
        card.className = "bg-white p-4 rounded-xl shadow-md border-l-4 transition-transform transform hover:scale-105 cursor-pointer";
        card.style.borderColor = homework.color;
        card.addEventListener('click', () => openHomeworkModal(homework));

        const dueDate = luxon.DateTime.fromISO(homework.due_date);
        
        let tasksHtml = '<p class="text-sm text-slate-500 italic mt-2">Aucune tâche pour ce devoir.</p>';
        if(homework.tasks && homework.tasks.length > 0) {
            tasksHtml = homework.tasks.map(task => {
                const isCompleted = task.is_completed == 1; // MySQL renvoie 0 ou 1
                return `
                    <div class="flex items-center space-x-2 mt-2">
                        <input type="checkbox" id="task-${task.id}-${homework.id}" data-task-id="${task.id}" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" ${isCompleted ? 'checked' : ''}>
                        <label for="task-${task.id}-${homework.id}" class="text-sm text-slate-700 ${isCompleted ? 'line-through text-slate-400' : ''}">${escapeHTML(task.description)}</label>
                    </div>
                `;
            }).join('');
        }

        card.innerHTML = `
            <div class="flex justify-between items-start">
                <div>
                    <p class="font-bold text-lg">${escapeHTML(homework.title)}</p>
                    <p class="text-sm text-slate-500">${escapeHTML(homework.subject || 'Matière non spécifiée')}</p>
                </div>
                <div class="text-right flex-shrink-0 ml-2">
                    <p class="font-semibold text-indigo-600">${dueDate.toFormat('dd')}</p>
                    <p class="text-xs text-slate-500">${dueDate.toFormat('MMM')}</p>
                </div>
            </div>
            <div class="mt-3">${tasksHtml}</div>
        `;
        
        card.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            checkbox.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleTaskStatus(e.target.dataset.taskId, e.target.checked);
            });
        });

        upcomingHomeworkList.appendChild(card);
    }

    function renderCalendarEvents() {
        if (!calendar) return;
        const events = allHomeworks.map(h => ({
            id: h.id,
            title: h.title,
            start: h.due_date,
            allDay: true,
            backgroundColor: h.color,
            borderColor: h.color,
        }));
        calendar.removeAllEvents();
        calendar.addEventSource(events);
    }

    // --- LOGIQUE DE LA MODALE ---
    
    function openHomeworkModal(homework = null, dateStr = null) {
        homeworkForm.reset();
        document.getElementById('homework-id').value = '';
        document.getElementById('tasks-container').innerHTML = '';
        deleteHomeworkBtn.classList.add('hidden');

        if (homework) { // Mode édition
            modalTitle.textContent = 'Modifier le devoir';
            document.getElementById('homework-id').value = homework.id;
            document.getElementById('title').value = homework.title;
            document.getElementById('subject').value = homework.subject || '';
            document.getElementById('due-date').value = homework.due_date;
            renderColorPicker(homework.color);
            if (homework.tasks && homework.tasks.length > 0) {
                homework.tasks.forEach(task => addTaskInput(task.description));
            }
            deleteHomeworkBtn.classList.remove('hidden');
        } else { // Mode création
            modalTitle.textContent = 'Ajouter un devoir';
            renderColorPicker(colors[Math.floor(Math.random() * colors.length)]);
            if(dateStr) document.getElementById('due-date').value = dateStr;
            addTaskInput();
        }

        homeworkModal.classList.remove('hidden');
        homeworkModal.classList.add('flex');
        setTimeout(() => {
            homeworkModalContent.classList.remove('scale-95', 'opacity-0');
        }, 10);
    }
    
    function closeHomeworkModal() {
        homeworkModalContent.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            homeworkModal.classList.add('hidden');
            homeworkModal.classList.remove('flex');
        }, 300);
    }

    function renderColorPicker(selectedColor) {
        const container = document.getElementById('color-picker');
        container.innerHTML = '';
        colors.forEach(color => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `w-8 h-8 rounded-full transition-transform transform hover:scale-110 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500`;
            button.style.backgroundColor = color;
            button.dataset.color = color;
            if (color === selectedColor) {
                button.classList.add('ring-2', 'ring-offset-2', 'ring-indigo-500');
            }
            button.addEventListener('click', () => {
                document.querySelector('#color-picker .ring-2')?.classList.remove('ring-2', 'ring-offset-2', 'ring-indigo-500');
                button.classList.add('ring-2', 'ring-offset-2', 'ring-indigo-500');
            });
            container.appendChild(button);
        });
    }
    
    function addTaskInput(value = '') {
        const container = document.getElementById('tasks-container');
        const div = document.createElement('div');
        div.className = 'flex items-center space-x-2';
        div.innerHTML = `
            <input type="text" class="task-description flex-grow border-slate-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="Description de la tâche" value="${escapeHTML(value)}">
            <button type="button" class="remove-task-btn text-red-500 hover:text-red-700 p-1 rounded-full"><i class="ph ph-trash"></i></button>
        `;
        div.querySelector('.remove-task-btn').addEventListener('click', () => div.remove());
        container.appendChild(div);
    }

    // --- GESTION DES ÉVÉNEMENTS ---

    addHomeworkBtn.addEventListener('click', () => openHomeworkModal());
    closeModalBtn.addEventListener('click', closeHomeworkModal);
    homeworkModal.addEventListener('click', (e) => {
        if (e.target === homeworkModal) closeHomeworkModal();
    });
    document.getElementById('add-task-btn').addEventListener('click', () => addTaskInput());
    
    homeworkForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const selectedColorEl = document.querySelector('#color-picker .ring-2');
        
        const homeworkData = {
            id: document.getElementById('homework-id').value || null,
            title: document.getElementById('title').value,
            subject: document.getElementById('subject').value,
            dueDate: document.getElementById('due-date').value,
            color: selectedColorEl ? selectedColorEl.dataset.color : null,
            tasks: Array.from(document.querySelectorAll('.task-description')).map(input => ({ description: input.value }))
        };

        try {
            const response = await fetch('api.php?action=save_homework', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(homeworkData)
            });
            const result = await response.json();
            if (result.status === 'success') {
                closeHomeworkModal();
                loadInitialData(); // Recharger les données pour tout mettre à jour
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            console.error(error);
            alert('Erreur lors de la sauvegarde : ' + error.message);
        }
    });
    
    deleteHomeworkBtn.addEventListener('click', async () => {
        const id = document.getElementById('homework-id').value;
        if (id && confirm("Êtes-vous sûr de vouloir supprimer ce devoir ?")) {
            try {
                const response = await fetch('api.php?action=delete_homework', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const result = await response.json();
                if (result.status === 'success') {
                    closeHomeworkModal();
                    loadInitialData();
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                console.error(error);
                alert('Erreur lors de la suppression.');
            }
        }
    });

    // --- ACTIONS UTILISATEUR (Tâches) ---
    async function toggleTaskStatus(taskId, isCompleted) {
        try {
            await fetch('api.php?action=toggle_task', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ taskId, isCompleted })
            });
        } catch (error) {
            console.error("Erreur lors de la mise à jour de la tâche :", error);
        }
    }
    
    // --- UTILITAIRES ---
    function escapeHTML(str) {
        const p = document.createElement('p');
        p.textContent = str;
        return p.innerHTML;
    }

    // --- Démarrage de l'application ---
    initializeCalendar();
    loadInitialData();
});
