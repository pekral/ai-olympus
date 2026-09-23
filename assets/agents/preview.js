const stateSelect = document.querySelector('#agent-state');
const themeSelect = document.querySelector('#preview-theme');
const pauseMotion = document.querySelector('#pause-motion');
const status = document.querySelector('#preview-status');

function updatePreview() {
    const state = stateSelect.value;
    const label = stateSelect.selectedOptions[0].textContent;
    const motion = pauseMotion.checked ? 'paused' : 'auto';

    document.body.dataset.theme = themeSelect.value;
    document.querySelectorAll('.ai-agent-activity').forEach((activity) => {
        activity.dataset.agentState = state;
        activity.dataset.motion = motion;
    });
    document.querySelectorAll('.agent-state').forEach((element) => {
        element.textContent = label;
    });
    status.textContent = label + (pauseMotion.checked
        ? ' · Animation paused.'
        : ' · Animation follows your system motion preference.');
}

stateSelect.addEventListener('change', updatePreview);
themeSelect.addEventListener('change', updatePreview);
pauseMotion.addEventListener('change', updatePreview);
document.querySelector('.preview-controls').addEventListener('submit', (event) => event.preventDefault());
updatePreview();
