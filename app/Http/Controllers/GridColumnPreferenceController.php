<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Crm\CrmApiController;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GridColumnPreferenceController extends CrmApiController
{
    private const GRIDS = [
        'crm.parties.list' => [
            'permission' => 'CRM_VIEW',
            'required' => ['name'],
            'columns' => [
                'row' => 'ردیف', 'name' => 'نام', 'nature' => 'ماهیت', 'identifier' => 'شناسه / کد ملی',
                'type' => 'گروه', 'activity' => 'زیرگروه', 'person' => 'فرد مرتبط', 'contact' => 'تماس اصلی',
                'province' => 'استان', 'county' => 'شهرستان', 'city' => 'شهر', 'mobile' => 'موبایل', 'address' => 'آدرس اصلی', 'status' => 'وضعیت رکورد', 'cooperationStatus' => 'وضعیت همکاری', 'actions' => 'عملیات',
            ],
        ],
        // Mirrors the authenticated access of the existing Users list route.
        'admin.users.list' => [
            'permission' => null,
            'required' => ['user'],
            'columns' => [
                '__rowNumber' => 'ردیف', 'UserCode' => 'کد کاربر', 'user' => 'کاربر', 'userType' => 'نوع کاربر',
                'contact' => 'تماس', 'RolesCount' => 'تعداد نقش‌ها', 'LastLoginDate' => 'آخرین ورود',
                'status' => 'وضعیت', 'actions' => 'عملیات',
            ],
        ],
        'admin.users.roles' => [
            'permission' => null, 'required' => ['roleName', 'access'],
            'columns' => ['access' => 'دسترسی', 'RoleCode' => 'کد', 'roleName' => 'نقش', 'UsersCount' => 'کاربران', '__rowNumber' => 'ردیف'],
        ],
        'admin.users.positions' => [
            'permission' => null, 'required' => ['position', 'assign'],
            'columns' => ['assign' => 'انتصاب', 'PositionCode' => 'کد', 'position' => 'سمت', 'UnitName' => 'واحد', '__rowNumber' => 'ردیف'],
        ],
        'admin.roles.users' => [
            'permission' => null, 'required' => ['user'],
            'columns' => ['user' => 'کاربر', 'Email' => 'ایمیل', 'Mobile' => 'موبایل', 'actions' => 'عملیات', '__rowNumber' => 'ردیف'],
        ],
        'admin.roles.list' => [
            'permission' => null, 'required' => ['roleName'],
            'columns' => ['RoleCode' => 'کد', 'roleName' => 'نقش', 'UsersCount' => 'کاربران', 'MenusCount' => 'منوها', 'ReportsCount' => 'گزارش‌ها', 'status' => 'وضعیت', 'actions' => 'عملیات', '__rowNumber' => 'ردیف'],
        ],
        'admin.roles.menus' => [
            'permission' => null, 'required' => ['title', 'access'],
            'columns' => ['access' => 'دسترسی', 'title' => 'عنوان', 'MenuTitle' => 'منو', '__rowNumber' => 'ردیف'],
        ],
        'admin.roles.reports' => [
            'permission' => null, 'required' => ['title', 'access'],
            'columns' => ['access' => 'دسترسی', 'title' => 'عنوان', 'MenuTitle' => 'منو', '__rowNumber' => 'ردیف'],
        ],
        'admin.roles.permissions' => [
            'permission' => null, 'required' => ['title', 'access'],
            'columns' => ['access' => 'دسترسی', 'title' => 'عنوان', 'PermissionGroup' => 'گروه', 'PermissionCode' => 'کد', '__rowNumber' => 'ردیف'],
        ],
        'admin.menus.list' => [
            'permission' => null, 'required' => ['title'],
            'columns' => ['MenuCode' => 'کد', 'title' => 'عنوان', 'MenuKind' => 'نوع', 'Url' => 'نشانی', 'ChildrenCount' => 'زیرمنو', 'RolesCount' => 'نقش‌ها', 'SortOrder' => 'ترتیب', 'status' => 'وضعیت', 'actions' => 'عملیات', '__rowNumber' => 'ردیف'],
        ],
        'admin.parameters.list' => [
            'permission' => null, 'required' => ['caption'],
            'columns' => ['MasterParameterID' => 'شناسه', 'ParameterName' => 'نام', 'caption' => 'عنوان', 'DataType' => 'نوع', 'ControlType' => 'کنترل', 'LookupProcedure' => 'رویه', 'UsageCount' => 'کاربرد', 'status' => 'وضعیت', 'actions' => 'عملیات', '__rowNumber' => 'ردیف'],
        ],
        'admin.units.list' => [
            'permission' => null, 'required' => ['unit'],
            'columns' => ['UnitCode' => 'کد', 'unit' => 'واحد', 'ParentUnitDisplay' => 'والد', 'status' => 'وضعیت', 'actions' => 'عملیات', '__rowNumber' => 'ردیف'],
        ],
        'admin.positions.list' => [
            'permission' => null, 'required' => ['position'],
            'columns' => ['PositionCode' => 'کد', 'position' => 'سمت', 'unit' => 'واحد', 'ParentPositionDisplay' => 'والد', 'status' => 'وضعیت', 'actions' => 'عملیات', '__rowNumber' => 'ردیف'],
        ],
        'messages.types' => [
            'permission' => null, 'required' => ['type'],
            'columns' => ['MessageTypeCode' => 'کد', 'type' => 'نوع', 'status' => 'وضعیت', 'actions' => 'عملیات', '__rowNumber' => 'ردیف'],
        ],
        'messages.statuses' => [
            'permission' => null, 'required' => ['status'],
            'columns' => ['MessageStatusCode' => 'کد', 'status' => 'عنوان', 'isActive' => 'وضعیت', 'actions' => 'عملیات', '__rowNumber' => 'ردیف'],
        ],
        'messages.priorities' => [
            'permission' => null, 'required' => ['priority'],
            'columns' => ['Code' => 'کد', 'priority' => 'اولویت', 'SortOrder' => 'ترتیب', 'isActive' => 'وضعیت', 'actions' => 'عملیات', '__rowNumber' => 'ردیف'],
        ],
        'messages.inbox' => [
            'permission' => null, 'required' => ['subject'],
            'columns' => ['MessageNumber' => 'شماره', 'MessageTypeName' => 'نوع', 'PriorityName' => 'اولویت', 'subject' => 'موضوع', 'SenderName' => 'فرستنده', 'CreateDate' => 'تاریخ', 'MessageStatusName' => 'وضعیت', 'DueDate' => 'مهلت', 'actions' => 'عملیات', '__rowNumber' => 'ردیف'],
        ],
        'messages.sent' => [
            'permission' => null, 'required' => ['subject'],
            'columns' => ['MessageNumber' => 'شماره', 'MessageTypeName' => 'نوع', 'PriorityName' => 'اولویت', 'subject' => 'موضوع', 'SenderName' => 'فرستنده', 'CreateDate' => 'تاریخ', 'MessageStatusName' => 'وضعیت', 'DueDate' => 'مهلت', 'actions' => 'عملیات', '__rowNumber' => 'ردیف'],
        ],
        'messages.archive' => [
            'permission' => null, 'required' => ['subject'],
            'columns' => ['MessageNumber' => 'شماره', 'MessageTypeName' => 'نوع', 'PriorityName' => 'اولویت', 'subject' => 'موضوع', 'SenderName' => 'فرستنده', 'CreateDate' => 'تاریخ', 'MessageStatusName' => 'وضعیت', 'DueDate' => 'مهلت', 'actions' => 'عملیات', '__rowNumber' => 'ردیف'],
        ],
        'reports.manage' => [
            'permission' => null, 'required' => ['title'],
            'columns' => ['ReportCode' => 'کد', 'title' => 'عنوان', 'ProcedureName' => 'رویه', 'MenuTitle' => 'منو', 'ParametersCount' => 'پارامترها', 'RolesCount' => 'نقش‌ها', 'outputs' => 'خروجی', 'status' => 'وضعیت', 'actions' => 'عملیات', '__rowNumber' => 'ردیف'],
        ],
        'reports.parameters' => [
            'permission' => null, 'required' => ['caption', 'link'],
            'columns' => ['link' => 'اتصال', 'ParameterName' => 'نام', 'caption' => 'عنوان', 'DataType' => 'نوع', 'ControlType' => 'کنترل', 'LookupProcedure' => 'رویه', '__rowNumber' => 'ردیف'],
        ],
        'workflow.instances' => [
            'permission' => 'WORKFLOW_VIEW', 'required' => ['definition'],
            'columns' => ['InstanceNumber' => 'شماره', 'definition' => 'فرایند', 'entity' => 'موجودیت', 'Status' => 'وضعیت', 'currentStep' => 'مرحله', 'StartedByName' => 'آغازگر', 'StartedAt' => 'تاریخ', 'actions' => 'عملیات', '__rowNumber' => 'ردیف'],
        ],
        'crm.product-categories' => [
            'permission' => 'CRM_VIEW', 'required' => ['name'],
            'columns' => ['name' => 'نام', 'Code' => 'کد', 'SortOrder' => 'ترتیب', 'children' => 'زیرگروه', 'usage' => 'کاربرد', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'crm.persons.list' => [
            'permission' => 'CRM_VIEW', 'required' => ['name'],
            'columns' => ['row' => 'ردیف', 'name' => 'نام', 'identifier' => 'شناسه', 'birth' => 'تولد', 'parties' => 'طرف‌حساب', 'contact' => 'تماس', 'province' => 'استان', 'county' => 'شهرستان', 'city' => 'شهر', 'mobile' => 'موبایل', 'address' => 'آدرس', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'crm.persons.parties' => [
            'permission' => 'CRM_VIEW', 'required' => ['party'],
            'columns' => ['party' => 'طرف‌حساب', 'nature' => 'ماهیت', 'PositionName' => 'سمت', 'RoleNames' => 'نقش', 'status' => 'وضعیت'],
        ],
        'crm.brands.parties' => [
            'permission' => 'CRM_VIEW', 'required' => ['party'],
            'columns' => ['party' => 'طرف‌حساب', 'nature' => 'ماهیت', 'partyStatus' => 'وضعیت', 'rows' => 'دسته‌ها'],
        ],
        'crm.parties.addresses' => [
            'permission' => 'CRM_VIEW', 'required' => ['text'],
            'columns' => ['AddressTitleName' => 'عنوان', 'text' => 'نشانی', 'PostalCode' => 'کدپستی', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'crm.parties.contacts' => [
            'permission' => 'CRM_VIEW', 'required' => ['value'],
            'columns' => ['ContactTypeName' => 'نوع', 'value' => 'مقدار', 'Description' => 'توضیحات', 'relatedPerson' => 'مخاطب', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'crm.parties.relations' => [
            'permission' => 'CRM_VIEW', 'required' => ['person'],
            'columns' => ['person' => 'فرد', 'primaryPhone' => 'تلفن', 'PositionName' => 'سمت', 'RoleNames' => 'نقش', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'crm.parties.brand-categories' => [
            'permission' => 'CRM_VIEW', 'required' => ['category'],
            'columns' => ['category' => 'دسته', 'brand' => 'برند', 'percent' => 'درصد', 'entry' => 'ورود', 'exit' => 'خروج', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'crm.parties.departments' => [
            'permission' => 'CRM_VIEW', 'required' => ['DepartmentName'],
            'columns' => ['DepartmentName' => 'دپارتمان', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'crm.parties.classifications' => [
            'permission' => 'CRM_VIEW', 'required' => ['PartyTypeName'],
            'columns' => ['PartyTypeName' => 'گروه', 'ActivityName' => 'زیرگروه', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'workflow.entity-types' => [
            'permission' => 'WORKFLOW_VIEW', 'required' => ['DisplayName'],
            'columns' => ['Code' => 'کد', 'DisplayName' => 'نام', 'ResolverClass' => 'کلاس', 'DefinitionCount' => 'تعریف‌ها', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'workflow.condition-fields' => [
            'permission' => 'WORKFLOW_VIEW', 'required' => ['DisplayName'],
            'columns' => ['Code' => 'کد', 'DisplayName' => 'عنوان', 'DataType' => 'نوع', 'Description' => 'توضیحات', 'SortOrder' => 'ترتیب', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'workflow.definition-categories' => [
            'permission' => 'WORKFLOW_VIEW', 'required' => ['Name'],
            'columns' => ['Code' => 'کد', 'Name' => 'نام', 'DefinitionCount' => 'تعریف‌ها', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'workflow.definition-versions' => [
            'permission' => 'WORKFLOW_VIEW', 'required' => ['VersionNo'],
            'columns' => ['VersionNo' => 'نسخه', 'Status' => 'وضعیت', 'StepCount' => 'مراحل', 'InstanceCount' => 'نمونه‌ها', 'Date_InsertFirst' => 'ثبت', 'PublishedAt' => 'انتشار', 'actions' => 'عملیات'],
        ],
        'workflow.templates' => [
            'permission' => 'WORKFLOW_VIEW', 'required' => ['Name'],
            'columns' => ['Code' => 'کد', 'Name' => 'نام', 'EntityType' => 'نوع', 'DefinitionName' => 'فرایند', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'workflow.template-parameters' => [
            'permission' => 'WORKFLOW_VIEW', 'required' => ['Caption'],
            'columns' => ['Code' => 'کد', 'Caption' => 'عنوان', 'SourceType' => 'منبع', 'DataType' => 'نوع', 'EntityType' => 'موجودیت', 'Description' => 'توضیحات', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'workflow.instance-steps' => [
            'permission' => 'WORKFLOW_VIEW', 'required' => ['step'],
            'columns' => ['step' => 'مرحله', 'StepType' => 'نوع', 'Status' => 'وضعیت', 'EnteredAt' => 'ورود', 'CompletedAt' => 'پایان'],
        ],
        'push.broadcasts' => [
            'permission' => 'PUSH_BROADCAST', 'required' => ['text'],
            'columns' => ['row' => 'ردیف', 'text' => 'متن', 'target' => 'گیرنده', 'status' => 'وضعیت', 'by' => 'فرستنده'],
        ],
        'messages.details.history' => [
            'permission' => null, 'required' => ['CreateDate'],
            'columns' => ['FromName' => 'فرستنده', 'ToName' => 'گیرنده', 'seen' => 'مشاهده', 'MessageStatusName' => 'وضعیت', 'Description' => 'توضیحات', 'CreateDate' => 'تاریخ'],
        ],
        'messages.details.recipients' => [
            'permission' => null, 'required' => ['FullName'],
            'columns' => ['FullName' => 'نام', 'seen' => 'مشاهده', 'Description' => 'توضیحات', 'CreateDate' => 'تاریخ', 'CreateUserName' => 'ثبت‌کننده'],
        ],
        'messages.details.attachments' => [
            'permission' => null, 'required' => ['FileName'],
            'columns' => ['FileName' => 'فایل', 'FileSize' => 'حجم', 'CreateUserName' => 'ثبت‌کننده', 'CreateDate' => 'تاریخ', 'download' => 'دریافت'],
        ],
        'projects.list' => [
            'permission' => null,
            'required' => ['ProjectTitle'],
            'columns' => [
                'ProjectTitle' => 'نام پروژه', 'ProjectCode' => 'کد', 'ProjectStatusTitle' => 'وضعیت پروژه',
                'PriorityName' => 'اولویت', 'IsActive' => 'فعالیت', 'ResponsibleName' => 'مسئول',
                'CreatorName' => 'ایجادکننده', 'ProgressPercent' => 'پیشرفت', 'MemberCount' => 'اعضا',
                'CreatedAt' => 'تاریخ ایجاد', 'ParentProjectID' => 'پروژهٔ والد', 'actions' => 'عملیات',
            ],
        ],
        'projects.details.members' => [
            'permission' => null, 'required' => ['FullName'],
            'columns' => ['FullName' => 'نام', 'Date_InsertFirst' => 'ثبت', 'actions' => 'عملیات', '__rowNumber' => 'ردیف'],
        ],
        'projects.details.contractors' => [
            'permission' => null, 'required' => ['name'],
            'columns' => ['name' => 'نام', 'Date_InsertFirst' => 'ثبت', 'actions' => 'عملیات', '__rowNumber' => 'ردیف'],
        ],
        'projects.details.tasks' => [
            'permission' => null, 'required' => ['Subject'],
            'columns' => ['Subject' => 'موضوع', 'MessageText' => 'متن', 'RecipientName' => 'گیرنده', 'PriorityName' => 'اولویت', 'MessageStatusName' => 'وضعیت', 'DueDate' => 'مهلت', 'CreateDate' => 'ثبت', '__rowNumber' => 'ردیف'],
        ],
        'projects.modal.members' => [
            'permission' => null, 'required' => ['FullName'],
            'columns' => ['rowNumber' => 'ردیف', 'FullName' => 'نام', 'Date_InsertFirst' => 'ثبت', 'actions' => 'عملیات'],
        ],
        'projects.modal.contractors' => [
            'permission' => null, 'required' => ['name'],
            'columns' => ['rowNumber' => 'ردیف', 'name' => 'نام', 'Date_InsertFirst' => 'ثبت', 'actions' => 'عملیات'],
        ],
        'crm.parties.projects' => [
            'permission' => 'CRM_VIEW', 'required' => ['title'],
            'columns' => ['code' => 'کد', 'title' => 'عنوان', 'role' => 'نقش', 'status' => 'وضعیت', 'responsible' => 'مسئول', 'progress' => 'پیشرفت', 'dates' => 'تاریخ'],
        ],
        'crm.persons.projects' => [
            'permission' => 'CRM_VIEW', 'required' => ['title'],
            'columns' => ['code' => 'کد', 'title' => 'عنوان', 'role' => 'نقش', 'status' => 'وضعیت', 'responsible' => 'مسئول', 'progress' => 'پیشرفت', 'dates' => 'تاریخ'],
        ],
        'crm.parties.interactions' => [
            'permission' => 'CRM_VIEW', 'required' => ['subject'],
            'columns' => ['date' => 'تاریخ', 'CreatedByName' => 'کاربر', 'type' => 'نوع', 'subject' => 'موضوع', 'party' => 'طرف‌حساب', 'project' => 'پروژه', 'Description' => 'توضیحات', 'Outcome' => 'نتیجه', 'actions' => 'عملیات'],
        ],
        'crm.persons.interactions' => [
            'permission' => 'CRM_VIEW', 'required' => ['subject'],
            'columns' => ['date' => 'تاریخ', 'CreatedByName' => 'کاربر', 'type' => 'نوع', 'subject' => 'موضوع', 'party' => 'طرف‌حساب', 'project' => 'پروژه', 'Description' => 'توضیحات', 'Outcome' => 'نتیجه', 'actions' => 'عملیات'],
        ],
        'crm.classification.departments' => [
            'permission' => 'CRM_MANAGE_MASTER_DATA', 'required' => ['DisplayName'],
            'columns' => ['row' => 'ردیف', 'Code' => 'کد', 'DisplayName' => 'نام', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'crm.classification.party-types' => [
            'permission' => 'CRM_MANAGE_MASTER_DATA', 'required' => ['DisplayName'],
            'columns' => ['row' => 'ردیف', 'Code' => 'کد', 'DisplayName' => 'نام', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'crm.classification.activities' => [
            'permission' => 'CRM_MANAGE_MASTER_DATA', 'required' => ['DisplayName'],
            'columns' => ['row' => 'ردیف', 'Code' => 'کد', 'DisplayName' => 'نام', 'status' => 'وضعیت', 'actions' => 'عملیات', 'PartyTypeName' => 'گروه'],
        ],
        'crm.directory.titles' => [
            'permission' => 'CRM_MANAGE_MASTER_DATA', 'required' => ['DisplayName'],
            'columns' => ['row' => 'ردیف', 'Code' => 'کد', 'DisplayName' => 'نام', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'crm.directory.positions' => [
            'permission' => 'CRM_MANAGE_MASTER_DATA', 'required' => ['DisplayName'],
            'columns' => ['row' => 'ردیف', 'Code' => 'کد', 'DisplayName' => 'نام', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'crm.directory.contact-roles' => [
            'permission' => 'CRM_MANAGE_MASTER_DATA', 'required' => ['DisplayName'],
            'columns' => ['row' => 'ردیف', 'Code' => 'کد', 'DisplayName' => 'نام', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'crm.directory.contact-types' => [
            'permission' => 'CRM_MANAGE_MASTER_DATA', 'required' => ['DisplayName'],
            'columns' => ['row' => 'ردیف', 'Code' => 'کد', 'DisplayName' => 'نام', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'crm.directory.address-titles' => [
            'permission' => 'CRM_MANAGE_MASTER_DATA', 'required' => ['DisplayName'],
            'columns' => ['row' => 'ردیف', 'Code' => 'کد', 'DisplayName' => 'نام', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'crm.directory.interaction-types' => [
            'permission' => 'CRM_MANAGE_MASTER_DATA', 'required' => ['DisplayName'],
            'columns' => ['row' => 'ردیف', 'Code' => 'کد', 'DisplayName' => 'نام', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'crm.directory.ownership-types' => [
            'permission' => 'CRM_MANAGE_MASTER_DATA', 'required' => ['DisplayName'],
            'columns' => ['row' => 'ردیف', 'Code' => 'کد', 'DisplayName' => 'نام', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
        'crm.geography.provinces' => [
            'permission' => 'CRM_MANAGE_MASTER_DATA', 'required' => ['DisplayName'],
            'columns' => ['row' => 'ردیف', 'Code' => 'کد', 'DisplayName' => 'نام', 'status' => 'وضعیت', 'actions' => 'عملیات', 'Population' => 'جمعیت'],
        ],
        'crm.geography.counties' => [
            'permission' => 'CRM_MANAGE_MASTER_DATA', 'required' => ['DisplayName'],
            'columns' => ['row' => 'ردیف', 'Code' => 'کد', 'DisplayName' => 'نام', 'status' => 'وضعیت', 'actions' => 'عملیات', 'ProvinceName' => 'استان', 'Population' => 'جمعیت'],
        ],
        'crm.geography.cities' => [
            'permission' => 'CRM_MANAGE_MASTER_DATA', 'required' => ['DisplayName'],
            'columns' => ['row' => 'ردیف', 'Code' => 'کد', 'DisplayName' => 'نام', 'status' => 'وضعیت', 'actions' => 'عملیات', 'ProvinceName' => 'استان', 'CountyName' => 'شهرستان', 'Population' => 'جمعیت'],
        ],
        'crm.geography.neighborhoods' => [
            'permission' => 'CRM_MANAGE_MASTER_DATA', 'required' => ['DisplayName'],
            'columns' => ['row' => 'ردیف', 'Code' => 'کد', 'DisplayName' => 'نام', 'status' => 'وضعیت', 'actions' => 'عملیات', 'CountyName' => 'شهرستان', 'CityName' => 'شهر'],
        ],
        'crm.geography.municipal-zones' => [
            'permission' => 'CRM_MANAGE_MASTER_DATA', 'required' => ['DisplayName'],
            'columns' => ['row' => 'ردیف', 'Code' => 'کد', 'DisplayName' => 'نام', 'status' => 'وضعیت', 'actions' => 'عملیات'],
        ],
    ];

    public function __invoke(Request $request, string $gridKey): JsonResponse
    {
        $grid = self::GRIDS[$gridKey] ?? abort(404);
        $userId = (int) $request->user()->getAuthIdentifier();
        abort_unless($userId > 0, 403);
        if ($grid['permission'] !== null) {
            $this->authorizeCrm($grid['permission']);
        }

        try {
            if ($request->isMethod('GET')) {
                $row = DB::selectOne('EXEC dbo.sp_UserGridColumnPreferences_Get ?, ?', [$userId, $gridKey]);
            } else {
                $validated = $request->validate(['version' => ['present', 'nullable', 'string', 'regex:/\A[0-9a-fA-F]{16}\z/']]);
                $version = $validated['version'];
                if ($request->isMethod('DELETE')) {
                    DB::selectOne('DECLARE @v binary(8) = CONVERT(binary(8), ?, 2); EXEC dbo.sp_UserGridColumnPreferences_Reset ?, ?, @v;', [$version, $userId, $gridKey]);
                    $row = null;
                } else {
                    $settings = $request->validate([
                        'visibility' => ['present', 'array'],
                        'order' => ['sometimes', 'list', 'max:100'],
                        'order.*' => ['required', 'string', 'distinct', \Illuminate\Validation\Rule::in(array_keys($grid['columns']))],
                        'widths' => ['sometimes', 'array:'.implode(',', array_keys($grid['columns']))],
                        'widths.*' => ['required', 'integer', 'between:60,800'],
                    ]);
                    $visibility = $settings['visibility'];
                    foreach ($visibility as $key => $visible) {
                        if (! array_key_exists($key, $grid['columns']) || ! is_bool($visible)
                            || (! $visible && in_array($key, $grid['required'], true))) {
                            throw ValidationException::withMessages(['visibility' => 'تنظیم ستون معتبر نیست.']);
                        }
                    }
                    $order = $settings['order'] ?? [];
                    // Version 2 stores presentation order in the serialized order of the boolean keys.
                    $orderedVisibility = array_fill_keys($order, true);
                    $columnSettings = array_replace($orderedVisibility, $visibility);
                    $widths = $settings['widths'] ?? [];
                    foreach ($widths as $key => $width) {
                        $columnSettings[$key] = ['visible' => $columnSettings[$key] ?? true, 'width' => (int) $width];
                    }
                    $row = DB::selectOne('DECLARE @v binary(8) = CONVERT(binary(8), ?, 2); EXEC dbo.sp_UserGridColumnPreferences_Save ?, ?, ?, ?, @v;', [
                        $version, $userId, $gridKey, json_encode((object) $columnSettings, JSON_THROW_ON_ERROR),
                        $widths ? 3 : ($order ? 2 : 1),
                    ]);
                }
            }
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 51041) {
                return response()->json(['message' => 'تنظیمات در دستگاه دیگری تغییر کرده است. دوباره بارگذاری کنید.'], 409);
            }
            throw $e;
        }

        $saved = array_intersect_key(json_decode($row->VisibilityJson ?? '{}', true), $grid['columns']);
        $order = (int) ($row->SchemaVersion ?? 1) >= 2 ? array_map('strval', array_keys($saved)) : [];
        $visibility = [];
        $widths = [];
        foreach ($saved as $key => $column) {
            $visibility[$key] = is_array($column) ? $column['visible'] : $column;
            if (is_array($column)) $widths[$key] = $column['width'];
        }
        foreach ($grid['required'] as $key) {
            unset($visibility[$key]);
        }

        return response()->json([
            'columns' => (object) $grid['columns'],
            'required' => $grid['required'],
            'visibility' => (object) $visibility,
            'order' => $order,
            'widths' => (object) $widths,
            'version' => $row->VersionToken ?? null,
        ])->header('Cache-Control', 'private, no-store');
    }
}
