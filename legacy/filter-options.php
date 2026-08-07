<div class="search-bar1 mb-5 d-flex align-items-center">
    <i class="bi bi-search text-secondary"></i>
    <input type="text" class="form-control ms-3" placeholder="Search in Drive" id="search-bar">
</div>

<!-- Filter Buttons with Dropdown Options (Without Dropdown Icons) -->
<div class="filter-buttons d-flex gap-2">
    <!-- Type Dropdown -->
    <div class="dropdown">
        <button class="btn btn-outline-secondary type-dropdown no-caret" type="button" id="dropdownType" data-bs-toggle="dropdown" aria-expanded="false">
            <span>Type</span>
        </button>
        <ul class="dropdown-menu" aria-labelledby="dropdownType">
            <li><a class="dropdown-item" href="#" data-value="Photos & Images"><i class="bi bi-image me-2" style="color: #F4B400;"></i>Photos & Images</a></li>
            <li><a class="dropdown-item" href="#" data-value="Audio"><i class="bi bi-file-music me-2" style="color: #34A853;"></i>Audio</a></li>
            <li><a class="dropdown-item" href="#" data-value="Video"><i class="bi bi-file-earmark-play me-2" style="color: #DB4437;"></i>Video</a></li>
            <li><a class="dropdown-item" href="#" data-value="Folder"><i class="bi bi-folder-fill me-2" style="color: #4285F4;"></i>Folder</a></li>
        </ul>
    </div>

    <!-- People Dropdown -->
    <div class="dropdown">
        <button class="btn btn-outline-secondary people-dropdown no-caret" type="button" id="dropdownPeople" data-bs-toggle="dropdown" aria-expanded="false">
            <span>People</span>
        </button>
        <ul class="dropdown-menu" aria-labelledby="dropdownPeople">
            <li><a class="dropdown-item" href="#" data-value="Foundation University"><i class="bi bi-building me-2" style="color: #4285F4;"></i>Foundation University</a></li>
            <li><a class="dropdown-item" href="#" data-value="Anyone with the link"><i class="bi bi-link me-2" style="color: #F4B400;"></i>Anyone with the link</a></li>
        </ul>
    </div>

    <!-- Modified Dropdown -->
    <div class="dropdown">
        <button class="btn btn-outline-secondary modified-dropdown no-caret" type="button" id="dropdownModified" data-bs-toggle="dropdown" aria-expanded="false">
            <span>Modified</span>
        </button>
        <ul class="dropdown-menu" aria-labelledby="dropdownModified">
            <li><a class="dropdown-item" href="#" data-value="Today"><i class="bi bi-clock me-2" style="color: #34A853;"></i>Today</a></li>
            <li><a class="dropdown-item" href="#" data-value="Last Week"><i class="bi bi-calendar me-2" style="color: #DB4437;"></i>Last Week</a></li>
            <li><a class="dropdown-item" href="#" data-value="Last Month"><i class="bi bi-calendar2-week me-2" style="color: #4285F4;"></i>Last Month</a></li>
            <li><a class="dropdown-item" href="#" data-value="This year (2024)"><i class="bi bi-calendar3 me-2" style="color: #F4B400;"></i>This year (2024)</a></li>
            <li><a class="dropdown-item" href="#" data-value="Last Year (2023)"><i class="bi bi-calendar-check me-2" style="color: #DB4437;"></i>Last Year (2023)</a></li>
        </ul>
    </div>

    <!-- Location Dropdown -->
    <div class="dropdown">
        <button class="btn btn-outline-secondary location-dropdown no-caret" type="button" id="dropdownLocation" data-bs-toggle="dropdown" aria-expanded="false">
            <span>Location</span>
        </button>
        <ul class="dropdown-menu" aria-labelledby="dropdownLocation">
            <li><a class="dropdown-item" href="#" data-value="Creative"><i class="bi bi-palette me-2" style="color: #4285F4;"></i>Creative</a></li>
        </ul>
    </div>
</div>

<!-- CSS to hide the dropdown caret icon -->
<style>
    .dropdown-toggle.no-caret::after {
        display: none;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Dropdown selection with option replacement and "x" button logic
    document.querySelectorAll('.dropdown-menu a').forEach(item => {
        item.addEventListener('click', function(event) {
            event.preventDefault();
            const parentDropdown = this.closest('.dropdown');
            const dropdownButton = parentDropdown.querySelector('button');
            const span = dropdownButton.querySelector('span');
            const selectedValue = this.getAttribute('data-value');

            // Update the button with the selected option and show "x" button
            span.innerHTML = `${selectedValue} <button class="btn btn-sm btn-outline-secondary ms-2 remove-selection">&times;</button>`;
            
            // Close other dropdowns
            closeOtherDropdowns(parentDropdown);
            
            // Add functionality to reset the dropdown when "x" is clicked
            const resetButton = dropdownButton.querySelector('.remove-selection');
            resetButton.addEventListener('click', function(e) {
                e.stopPropagation();
                span.textContent = dropdownButton.id.replace('dropdown', ''); // Reset to original text
            });
        });
    });

    // Function to close other dropdowns
    function closeOtherDropdowns(currentDropdown) {
        document.querySelectorAll('.dropdown-menu').forEach(dropdown => {
            const parentDropdown = dropdown.closest('.dropdown');
            if (parentDropdown !== currentDropdown) {
                bootstrap.Dropdown.getInstance(dropdown.previousElementSibling)?.hide();
            }
        });
    }
});
</script>
