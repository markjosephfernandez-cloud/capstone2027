<?php 
require 'head.php';
require 'login-check.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$pageTitle = 'Dashboard';

?>
<!DOCTYPE html>
<html lang="en">
<title>Dashboard</title>
<body>

  <?php include 'header.php'; ?>


  <!-- ======= Sidebar ======= -->
  <?php include 'sidebar.php'; ?>

 

  <main id="main" class="main">
    <div class="pagetitle">
        <h1><?php echo $pageTitle; ?></h1>
        <ol class="breadcrumb">
            <li class="breadcrumb-item active"><?php echo $pageTitle; ?></li>
        </ol>
    </div>


    <section class="section dashboard">
      <div class="row">

        <!-- Left side columns -->
        <div class="col-lg-8">
          <div class="row">

          <script>
    document.addEventListener("DOMContentLoaded", function () {
        // Fetch storage stats and update the cards dynamically
        fetch('storage_stats.php')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Failed to load storage statistics.');
                }
                return response.json();
            })
            .then(stats => {
                // Update the Storage Used card
                document.getElementById('storage-used-value').innerText = stats.used_space;
                document.getElementById('storage-used-percentage').innerText = `${stats.used_percentage}%`;

                // Update the Available Storage card
                document.getElementById('available-storage-value').innerText = stats.free_space;
                document.getElementById('total-storage-value').innerText = stats.total_space;

                // Update the Total Files card
                document.getElementById('total-files-value').innerText = stats.total_files;
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('storage-error').innerText = 'Error: Unable to load storage statistics.';
            });
    });
</script>

<div class="row">
    <!-- Storage Used Card -->
    <div class="col-xxl-4 col-md-6">
        <div class="card info-card sales-card">
            <div class="card-body">
                <h5 class="card-title">STORAGE USED</h5>
                <div class="d-flex align-items-center">
                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                        <i class="bi bi-cloud"></i>
                    </div>
                    <div class="ps-3">
                        <h6 id="storage-used-value">Loading...</h6>
                        <span class="text-success small pt-1 fw-bold" id="storage-used-percentage">...</span>
                        <span class="text-muted small pt-2 ps-1">of total storage</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Available Storage Card -->
    <div class="col-xxl-4 col-md-6">
        <div class="card info-card revenue-card">
            <div class="card-body">
                <h5 class="card-title">AVAILABLE STORAGE</h5>
                <div class="d-flex align-items-center">
                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                        <i class="bi bi-hdd"></i>
                    </div>
                    <div class="ps-3">
                        <h6 id="available-storage-value">Loading...</h6>
                        <span class="text-muted small pt-2 ps-1">of <span id="total-storage-value">...</span></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Files Card -->
    <div class="col-xxl-4 col-xl-12">
        <div class="card info-card customers-card">
            <div class="card-body">
                <h5 class="card-title">TOTAL FILES</h5>
                <div class="d-flex align-items-center">
                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                        <i class="bi bi-file-earmark-text" style="color: white;"></i>
                    </div>
                    <div class="ps-3">
                        <h6 id="total-files-value">Loading...</h6>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Error Message -->
<div id="storage-error" class="text-danger"></div>


<!-- Reports -->
<div class="col-12">
  <div class="card">
    <div class="card-body">
      <h5 class="card-title">Storage Overview</h5>

      <!-- Line Chart -->
      <div id="storageChart"></div>

      <script>
        document.addEventListener("DOMContentLoaded", () => {
            fetch('storage_stats.php')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Failed to load storage statistics.');
                    }
                    return response.json();
                })
                .then(stats => {
                    if (!stats.history) {
                        document.querySelector("#storageChart").innerHTML = '<p class="text-warning">No historical data available.</p>';
                        return;
                    }

                    const history = stats.history;
                    const latestYear = Math.max(...Object.keys(history).map(Number)); // Get latest year
                    const months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
                    const historicalData = months.map(month => history[latestYear][month] || 0); // Fill missing months with 0

                    new ApexCharts(document.querySelector("#storageChart"), {
                        series: [{
                            name: `Storage Used (GB) - ${latestYear}`,
                            data: historicalData
                        }],
                        chart: {
                            height: 350,
                            type: 'area',
                            toolbar: { show: false }
                        },
                        markers: {
                            size: 5,
                            colors: ['#4154f1'],
                            strokeColors: '#ffffff',
                            strokeWidth: 2,
                            hover: { size: 7 }
                        },
                        colors: ['#4154f1'],
                        fill: { type: "solid", opacity: 0.2 },
                        stroke: { curve: 'smooth', width: 3 },
                        xaxis: {
                            categories: months,
                            title: { text: 'Month' }
                        },
                        yaxis: {
                            title: { text: 'Storage Used (GB)' }
                        },
                        tooltip: {
                            x: { formatter: (val, { dataPointIndex }) => `${months[dataPointIndex]}, ${latestYear}` }
                        },
                        grid: {
                            show: true,
                            borderColor: '#e0e0e0'
                        },
                        legend: {
                            position: 'right',
                            horizontalAlign: 'center',
                            labels: { colors: '#4154f1' },
                            markers: { fillColors: ['#4154f1'] }
                        }
                    }).render();
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.querySelector("#storageChart").innerHTML = '<p class="text-danger">Error: Unable to load storage statistics.</p>';
                });
        });
      </script>

      <!-- End Line Chart -->
    </div>
  </div>
</div>
<!-- End Reports -->



            
  <div class="col-12">
  <div class="card storage-analytics overflow-auto">

    <div class="card-body">
      <h5 class="card-title">Storage Analytics</h5>

      <table class="table table-borderless">
        <thead>
          <tr>
            <th scope="col">FILE TYPE</th>
            <th scope="col">TOTAL SIZE (GB)</th>
            <th scope="col">PERCENTAGE USED</th>
          </tr>
        </thead>
        <tbody id="storageAnalyticsBody">
          <!-- Dynamic data will populate here -->
        </tbody>
      </table>

    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    fetch('storage_stats.php')
        .then(response => {
            if (!response.ok) throw new Error('Failed to load storage statistics.');
            return response.json();
        })
        .then(stats => {
            const totalUsed = Object.values(stats.file_categories).reduce((acc, val) => acc + val, 0); // Sum all sizes
            const categories = stats.file_categories;

            const tbody = document.getElementById("storageAnalyticsBody");
            tbody.innerHTML = ""; // Clear existing content

            // Function to get progress bar color
            const getColorByFileType = (type) => {
                switch (type.toLowerCase()) {
                    case "images": return "#34a853"; // Green
                    case "videos": return "#db4437"; // Red
                    case "audios": return "#ff8c00"; // Orange
                    default: return "#4285f4"; // Blue for Others
                }
            };

            // Populate table rows
            for (const [type, size] of Object.entries(categories)) {
                const percentageUsed = totalUsed > 0 ? ((size / totalUsed) * 100).toFixed(2) : 0;
                const progressBarColor = getColorByFileType(type);

                const row = `
                    <tr>
                        <td>${type}</td>
                        <td>${size.toFixed(2)} GB</td>
                        <td>
                            ${percentageUsed}%
                            <div class="progress">
                                <div class="progress-bar" role="progressbar" style="width: ${percentageUsed}%; background-color: ${progressBarColor};" aria-valuenow="${percentageUsed}" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </td>
                    </tr>
                `;

                tbody.innerHTML += row;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById("storageAnalyticsBody").innerHTML = `
                <tr>
                    <td colspan="3" class="text-danger">Error: Unable to load storage analytics.</td>
                </tr>`;
        });
});

</script>


          </div>
        </div><!-- End Left side columns -->

        <!-- Right side columns -->
        <div class="col-lg-4">
    <div class="card">
        <div class="filter">
            <a class="icon" href="#" data-bs-toggle="dropdown">
            

            </a>
  
        </div>
        <div class="card-body" style="max-height: 400px; overflow-y: auto;">

        <div>
</div>
        <button class="btn btn-primary btn-sm float-end me-4 mt-1" onclick="window.location.href='recent.php';">SEE ALL</button>
    <h5 class="card-title">Recent Videos and Pictures Activity</h5>

    <div class="activity">
    <?php
    // Check if session is already active
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Database connection parameters
    $servername = "localhost";
    $username = "root";
    $password = "";
    $dbname = "fm_system";

    // Create database connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Get the logged-in user's employee_id from the session
    $employee_id = $_SESSION['employee_id'] ?? null;

    /**
     * Function to fetch recent file paths for the system or a specific user.
     * Limits results to the past 7 days and avoids duplicates.
     */
   /**
 * Function to fetch recent file paths for all employees.
 * Limits results to the past 7 days and avoids duplicates.
 */
function getRecentFilePaths($conn) {
    // Define a time limit for recent files (e.g., 7 days)
    $timeLimit = date('Y-m-d H:i:s', strtotime('-7 days'));

    // Define allowed file extensions
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'mov'];
    $allowedExtensionsSQL = "'" . implode("', '", $allowedExtensions) . "'";

    // ✅ Fetch all employees' recent media files (no employee filter)
    $query = "
        SELECT 
            r.item_name, 
            r.item_type, 
            r.filepath, 
            MAX(r.timestamp) AS timestamp, 
            u.name AS user_name
        FROM recent r
        LEFT JOIN admin_users u ON r.employee_id = u.employee_id
        WHERE r.timestamp >= ? 
          AND LOWER(SUBSTRING_INDEX(r.filepath, '.', -1)) IN ($allowedExtensionsSQL)
        GROUP BY r.filepath, r.item_name, r.item_type, u.name
        ORDER BY MAX(r.timestamp) DESC
        LIMIT 10
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $timeLimit);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result) {
        die("Query Failed: " . $conn->error);
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}

// Fetch recent files for **all employees**
$recentFiles = getRecentFilePaths($conn);

    // Check if employee_id exists in the session
    if ($employee_id) {
        // Fetch recent files for the logged-in user or system-wide if no specific employee is set
        $recentFiles = getRecentFilePaths($conn, $employee_id);

        // Display activity items           
        if (empty($recentFiles)) {
            echo '<p>No recent activity found.</p>';
        } else {
            foreach ($recentFiles as $file) {
                ?>
                <div class="activity-item d-flex">
                    <!-- Time Ago -->
                    <div class="activite-label"><?php echo htmlspecialchars(timeAgo($file['timestamp'])); ?></div>

                    <!-- Badge -->
                    <i class="bi bi-circle-fill activity-badge 
                        <?php 
                        $ext = strtolower(pathinfo($file['filepath'], PATHINFO_EXTENSION));
                        echo in_array($ext, ['jpg', 'jpeg', 'png', 'gif']) ? 'text-success' : 
                             (in_array($ext, ['mp4', 'mov']) ? 'text-danger' : 'text-primary'); 
                        ?> align-self-start"></i>

                    <!-- Activity Content -->
                    <div class="activity-content">
                        <!-- File Type and Name -->
                        <strong><?php echo htmlspecialchars($file['user_name'] ?? 'Unknown'); ?></strong> accessed 
                        <?php echo ucfirst($file['item_type']); ?>
                        <a href="#" class="fw-bold text-dark"><?php echo htmlspecialchars($file['item_name']); ?></a>

                        <!-- File Path -->
                        <br>
                        <small class="text-muted">Path: <?php echo htmlspecialchars($file['filepath']); ?></small>
                    </div>
                </div><!-- End activity item -->
                <?php
            }
        }
    } else {
        echo '<p>No recent activity found. Please log in.</p>';
    }

    /**
     * Function to convert timestamp to "time ago" format, adjusting for Philippine Time (UTC+8)
     */
    function timeAgo($timestamp) {
        // Convert the timestamp to UTC+8
        $time_ago = strtotime($timestamp) + (8 * 3600);
        $current_time = time();
        $time_difference = $current_time - $time_ago;

        $seconds = $time_difference;
        $minutes = round($seconds / 60);
        $hours = round($seconds / 3600);
        $days = round($seconds / 86400);
        $weeks = round($seconds / 604800);
        $months = round($seconds / 2629440);
        $years = round($seconds / 31553280);

        if ($seconds < 60) {
            return "Just now";
        } elseif ($minutes < 60) {
            return ($minutes == 1) ? "one minute ago" : "$minutes minutes ago";
        } elseif ($hours < 24) {
            return ($hours == 1) ? "an hour ago" : "$hours hours ago";
        } elseif ($days < 7) {
            return ($days == 1) ? "yesterday" : "$days days ago";
        } elseif ($weeks < 4) {
            return ($weeks == 1) ? "a week ago" : "$weeks weeks ago";
        } elseif ($months < 12) {
            return ($months == 1) ? "a month ago" : "$months months ago";
        } else {
            return ($years == 1) ? "one year ago" : "$years years ago";
        }
    }
?>



</div>
</div>
</div>

          
          <!-- End Recent Activity -->

          <!-- Website Traffic -->
          <div class="card">
            <div class="filter">
              <a class="icon" href="#" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></a>
              <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                <li class="dropdown-header text-start">
                  <h6>Filter</h6>
                </li>

                <li><a class="dropdown-item" href="#">Today</a></li>
                <li><a class="dropdown-item" href="#">This Month</a></li>
                <li><a class="dropdown-item" href="#">This Year</a></li>
              </ul>
            </div>
            <div class="card-body pb-0">
  <h5 class="card-title">Storage Details <span>| Today</span></h5>

  <div id="PieChart" style="min-height: 400px;" class="echart"></div>

  <script>
    document.addEventListener("DOMContentLoaded", () => {
      // Fetch storage data
      fetch('storage_stats.php')
        .then(response => response.json())
        .then(stats => {
          const usedSpace = parseFloat(stats.used_space.split(' ')[0]); // Extract used space in GB
          const freeSpace = parseFloat(stats.free_space.split(' ')[0]); // Extract free space in GB

          // Initialize the pie chart
          echarts.init(document.querySelector("#PieChart")).setOption({
            tooltip: {
              trigger: 'item',
              formatter: '{a} <br/>{b}: {c} GB ({d}%)'
            },
            legend: {
              top: '5%',
              left: 'center'
            },
            series: [{
              name: 'Storage Details',
              type: 'pie',
              radius: ['40%', '70%'],
              avoidLabelOverlap: false,
              label: {
                show: false,
                position: 'center'
              },
              emphasis: {
                label: {
                  show: true,
                  fontSize: '18',
                  fontWeight: 'bold'
                }
              },
              labelLine: {
                show: false
              },
              data: [
                {
                  value: usedSpace,
                  name: 'Storage Used',
                  itemStyle: {
                    color: '#9A1B2F' // Maroon color for used storage
                  }
                },
                {
                  value: freeSpace,
                  name: 'Storage Left',
                  itemStyle: {
                    color: '#6c757d' // Gray color for free storage
                  }
                }
              ]
            }]
          });
        })
        .catch(error => {
          console.error('Error:', error);
          document.querySelector("#PieChart").innerHTML = '<p class="text-danger">Error: Unable to load storage details.</p>';
        });
    });
  </script>
</div>

          </div>

            </div>
          </div><!-- End News & Updates -->

        </div><!-- End Right side columns -->

      </div>
    </section>

  </main><!-- End #main -->

  <?php
require 'footer.php';
?>

  <a href="#" class="back-to-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>


</body>
<script src="assets/js/main.js"></script>

</html>
